<?php

namespace App\Command;

use App\Agent\AgentLoop;
use App\Agent\ContextBudget;
use App\Agent\HistoryCompactor;
use App\Agent\InferenceSpeed;
use App\Agent\MessageBag;
use App\Agent\SystemPromptBuilder;
use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Config\Backend;
use App\Config\ModelResolver;
use App\Config\ProviderName;
use App\Mcp\McpRegistry;
use App\Memory\ContextStore;
use App\Memory\FactExtractor;
use App\Permission\ProjectPermissions;
use App\Permission\SessionPermissions;
use App\Platform\SwitchablePlatform;
use App\Project\Project;
use App\Project\DockerConfig;
use App\Project\ProjectQuestions;
use App\Project\ProjectStore;
use App\Project\WorkingProject;
use App\Runtime\Interrupt;
use App\Session\SessionStore;
use App\Skills\Skill;
use App\Skills\SkillRegistry;
use App\TUI\ChatPane;
use App\TUI\LineEditor;
use App\TUI\StatusBar;
use App\TUI\Terminal;
use App\TUI\Scrollback;
use App\Usage\TokenCapReached;
use App\Usage\UsageMeter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sherpa', description: 'Sherpa — a development agent in your terminal')]
class SherpaCommand extends Command
{
    private bool $warnedAboutSessions = false;

    public function __construct(
        // The project is the directory Sherpa was launched from: asked about
        // once, found by its path every time after.
        private readonly ProjectQuestions $questions,
        private readonly WorkingProject $working,
        private readonly ProjectStore $projectStore,
        private readonly FactExtractor $factExtractor,
        private readonly SkillRegistry $skillRegistry,
        private readonly AgentLoop $agentLoop,
        private readonly SystemPromptBuilder $promptBuilder,
        // The concrete switch rather than the interface: this command is the
        // one place that decides which backend is in force. Everything else is
        // handed the same instance as a plain PlatformInterface.
        private readonly SwitchablePlatform $platform,
        private readonly ChatPane $chat,
        private readonly StatusBar $statusBar,
        private readonly Terminal $terminal,
        private readonly ContextBudget $budget,
        private readonly HistoryCompactor $compactor,
        private readonly Toolbox $toolbox,
        private readonly LineEditor $lineEditor,
        private readonly ProjectPermissions $projectPermissions,
        private readonly SessionPermissions $sessionPermissions,
        private readonly Interrupt $interrupt,
        private readonly McpRegistry $mcp,
        private readonly ModelResolver $models,
        // Which backend, which model, and every way they change.
        private readonly ModelSession $model,
        // Everything typed with a slash in front of it.
        private readonly SlashCommands $commands,
        private readonly InferenceSpeed $speed,
        private readonly ContextStore $contextStore,
        private readonly UsageMeter $usage,
        private readonly SessionStore $sessions,
        private readonly Scrollback $scrollback,
        /**
         * Whether a session ends by asking the model what was worth
         * remembering: how project memory fills up, at one more request per
         * session. SHERPA_FACT_EXTRACTION.
         */
        private readonly bool $extractFacts = true,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('backend', 'b', InputOption::VALUE_REQUIRED, 'api (online model) or ollama (local model), for this run')
            ->addOption('provider', 'P', InputOption::VALUE_REQUIRED, 'A recorded API provider (see /provider), for this run')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Model to use, instead of the configured one')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Context window size, in tokens')
            // false when absent, null when given bare: `-r` is "the last one",
            // `-r 3` or `-r <id>` a particular one, as /resume takes them.
            ->addOption('resume', 'r', InputOption::VALUE_OPTIONAL, 'Pick this project\'s last conversation back up (or the n-th of /sessions)', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Whatever happens — Ctrl+C, an uncaught error, a fatal — the tty must
        // not be handed back in raw mode. A shutdown function covers the paths
        // a signal handler cannot.
        register_shutdown_function(function () {
            $this->terminal->restoreSane();
            // MCP servers are child processes. Whatever ends the session —
            // /exit, Ctrl+C, a fatal — they are not left running behind it.
            $this->mcp->shutdown();
        });

        // Ctrl+C cancels the turn while one is running; at an idle prompt, or on
        // a second press, it quits through here.
        $this->interrupt->install(function () {
            $this->terminal->restoreSane();
            echo "\n";
            exit(0);
        });

        // Kept from the first line, for Page Up at the prompt. Only when someone
        // is there to scroll: a pipe has its own copy of everything.
        if ($this->lineEditor->isInteractive()) {
            $this->scrollback->start();
        }

        $this->statusBar->renderWelcome();

        // Where the model runs, settled before anything is asked of it: a model
        // name only means something to the backend that serves it.
        $flag = $input->getOption('backend');
        $flagged = is_string($flag) && trim($flag) !== '';
        $requested = $flagged ? Backend::parse($flag) : null;

        if ($flagged && $requested === null) {
            echo Terminal::RED . "✗ Unknown backend: {$flag}. Possible values: api, ollama.\n" . Terminal::RESET;

            return Command::FAILURE;
        }

        // A provider is an API one: naming it settles the backend too.
        $provider = $input->getOption('provider');
        if (is_string($provider) && trim($provider) !== '') {
            $name = ProviderName::normalize($provider) ?? trim($provider);
            $known = array_keys($this->models->providers());

            if (!in_array($name, $known, true)) {
                echo Terminal::RED . "✗ Unknown provider: {$provider}.\n" . Terminal::RESET;
                echo Terminal::GRAY . '  ' . ($known === []
                    ? 'None recorded yet: start sherpa and use /provider add.'
                    : 'Known: ' . implode(', ', $known) . '.') . "\n" . Terminal::RESET;

                return Command::FAILURE;
            }

            if ($requested === Backend::Ollama) {
                echo Terminal::RED . "✗ --provider is an online provider; it cannot go with -b ollama.\n" . Terminal::RESET;

                return Command::FAILURE;
            }

            $this->models->useProvider($name);
            $requested = Backend::Api;
            $flagged = true;
        }

        $backend = $this->models->backend($requested);
        $this->platform->switchTo($backend);

        // Nobody said where the API is. In a terminal that is a question, not
        // a failure: the default backend should not need the README first.
        if ($backend === Backend::Api && !$this->model->configureApiEndpoint()) {
            $backend = Backend::Ollama;
            $this->platform->switchTo($backend);
        }

        if (!$this->platform->isAvailable()) {
            $this->model->explainUnavailable($backend);

            return Command::FAILURE;
        }

        // Which model, and how large a window. Asked once per machine, then
        // remembered — and asked before the project, because the answer belongs
        // to the machine rather than to what is being worked on.
        $context = $input->getOption('context');
        $profile = $this->model->resolve(
            $input->getOption('model'),
            is_numeric($context) ? (int) $context : null,
            $backend,
            rememberAsDefault: !$flagged,
        );
        if ($profile === null) {
            echo Terminal::GRAY . "Goodbye.\n" . Terminal::RESET;
            return Command::SUCCESS;
        }

        if ($profile->model === '') {
            // Only reachable without a terminal, on an API nobody named a model
            // for: there is no list to offer and no name worth guessing.
            echo Terminal::RED . "✗ No model chosen for the API.\n" . Terminal::RESET;
            echo Terminal::GRAY . "  Set SHERPA_API_MODEL, pass -m <model>, or run sherpa in a terminal to choose.\n"
                . Terminal::RESET;

            return Command::FAILURE;
        }

        $this->model->apply($profile);
        $this->model->announce($profile);
        $this->model->settleEmbeddings($backend);

        // The project is the directory Sherpa was launched from — the one Sherpa
        // knows there, or a new one, asked about now and written at the first
        // message. No menu: the directory already says which project it is.
        if (!$this->openProject()) {
            return Command::FAILURE;
        }
        $project = $this->working->project();

        // Session-scoped and in memory: it holds this conversation's elided
        // tool output, which describes files as they were an hour ago.
        $this->contextStore->open();
        $this->skillRegistry->setProjectPath($project->path);
        $this->skillRegistry->load();

        // What "/" lists: the commands, then the skills, which run as commands
        // too. Read at each keystroke, so a skill written mid-session is there.
        $this->lineEditor->useCompletions(fn(): array => [
            ...SlashCommands::catalogue(),
            ...array_values(array_map(
                fn(Skill $skill) => ['name' => '/' . $skill->name, 'hint' => '[request]', 'description' => 'skill · ' . $skill->description],
                array_filter($this->skillRegistry->all(), fn(Skill $skill) => !$this->isCommand('/' . $skill->name)),
            )),
        ]);

        // Third-party tools join the toolbox here, before the first turn is
        // built, so their schemas are in the very first request.
        $this->mcp->load($this->toolbox);
        foreach ($this->mcp->report() as $line) {
            echo Terminal::GRAY . '  MCP · ' . Terminal::plain($line, singleLine: true) . "\n" . Terminal::RESET;
        }

        $bag = new MessageBag();
        $bag->system($this->promptBuilder->build($project));

        echo Terminal::GREEN . ($this->working->isSaved() ? '✓ Project loaded: ' : '✓ New project: ')
            . Terminal::BOLD . $project->name . Terminal::RESET
            . Terminal::GRAY . " ({$project->stack})"
            . ($this->working->isSaved() ? '' : ' — saved at your first message')
            . "\n" . Terminal::RESET;
        echo Terminal::GRAY . "  /help  /model  /provider  /backend  /memory  /sessions  /mcp  /reset  /exit\n" . Terminal::RESET;

        // A standing grant made in an earlier session runs unprompted from here
        // on. Say so on the way in rather than let it be discovered by surprise.
        $standing = $this->projectPermissions->all();
        if ($standing !== []) {
            echo Terminal::YELLOW . '  ⚠ Allowed without asking: ' . implode(', ', $standing)
                . Terminal::GRAY . "  (/permissions to withdraw)\n" . Terminal::RESET;
        }

        $resume = $input->getOption('resume');
        if ($resume !== false) {
            $this->commands->resume(is_string($resume) ? trim($resume) : '', $bag, $project);
        } elseif (($last = $this->sessions->latest()) !== null) {
            // A feature nobody knows about does not exist. The conversation
            // left behind is named, with how to get it back, and nothing more:
            // opening a new one is still what an empty prompt means.
            echo Terminal::GRAY . '  Last conversation: "'
                . Terminal::plain(mb_strimwidth($last->title, 0, 60, '…'), singleLine: true)
                . '" (' . $last->updatedAt->format('m-d H:i') . ") — /resume to pick it up\n" . Terminal::RESET;
        }

        echo "\n";

        // Main REPL loop
        $warnedAboutConfig = false;
        // Ollama loads lazily, so there is nothing to ask about until a reply
        // has come back. Checked once, after the first one.
        $checkedResidency = false;

        while (true) {
            // The path, the container and the stack were read at startup and
            // this session has been running on them since. It cannot adopt a
            // change made out there, so it says so once and leaves the decision
            // to restart to the user.
            if (!$warnedAboutConfig && $this->projectStore->changedOnDisk()) {
                $warnedAboutConfig = true;
                $this->chat->showInfo(
                    'The projects configuration changed on disk. '
                    . 'This session keeps what it read at startup — '
                    . 'restart Sherpa to pick up a changed path, container or stack.'
                );
            }

            $this->renderStatus($project, $bag);
            $userInput = $this->readUserInput();

            if ($userInput === null || $userInput === '/exit' || $userInput === '/quit') {
                break;
            }

            if ($userInput === '') {
                continue;
            }

            // A skill run as a command: its text goes to the model with the
            // request, rather than waiting for the model to think of loading it.
            $shownInput = $userInput;
            if (str_starts_with($userInput, '/') && ($skillMessage = $this->skillMessage($userInput)) !== null) {
                $userInput = $skillMessage;
            }

            // Slash commands
            if (str_starts_with($userInput, '/')) {
                if ($this->commands->handle($userInput, $bag, $this->working->project())) {
                    // A forgotten project ends its session: there is nothing
                    // left to work in, and any further turn would recreate it.
                    if ($this->working->isForgotten()) {
                        break;
                    }

                    continue;
                }
                echo Terminal::RED . "Unknown command: {$userInput}\n" . Terminal::RESET;
                continue;
            }

            // The first message is the decision to work here: a held project
            // becomes a real one, with its memory and its history on disk.
            if ($this->working->save()) {
                $project = $this->working->project();
                echo Terminal::GRAY . "  ✓ {$project->name} saved ({$project->path})\n" . Terminal::RESET;
            }

            $bag->user($userInput);
            $this->chat->addUserMessage($shownInput);

            $this->interrupt->beginTurn();

            try {
                $result = $this->agentLoop->run($bag);

                if ($result->interrupted) {
                    $this->chat->showInfo('Turn interrupted. The conversation is kept — Ctrl+C again to leave.');
                } elseif ($result->repliedBeforeStop) {
                    $this->chat->showInfo('The model kept repeating a tool call after its reply; turn stopped there.');
                } elseif ($result->repetitionDetected) {
                    $this->chat->showError(
                        'The model kept repeating the same tool call without getting anywhere; turn stopped. '
                        . 'Rephrase, or give it what it is missing.'
                    );
                } elseif ($result->maxIterationsReached) {
                    $this->chat->showError('Iteration limit reached.');
                }
            } catch (TokenCapReached $e) {
                // Not a failure of anything: the limit the user set, doing its job.
                $this->chat->showInfo($e->getMessage());
            } catch (\Throwable $e) {
                $this->chat->showError('Error: ' . $e->getMessage());
            } finally {
                $this->interrupt->endTurn();
            }

            if (!$checkedResidency) {
                $checkedResidency = true;
                $this->reportResidency();
            }

            $this->recordSession($bag);

            if ($this->usage->shouldWarn()) {
                $this->chat->showInfo(sprintf(
                    'This session has used %s of its %s token cap.',
                    UsageMeter::number($this->usage->total()),
                    UsageMeter::number($this->usage->cap()),
                ));
            }
        }

        // Before the extraction, so what is written is the session the user
        // had, not the bookkeeping that follows it.
        // Nothing is written for a forgotten project on the way out: the
        // conversation or the facts would bring back what was just deleted.
        $forgotten = $this->working->isForgotten();

        if (!$forgotten) {
            $this->recordSession($bag);
        }
        $this->writeTranscript($bag);

        // End-of-session: extract and persist facts. Wrapped in a turn so the
        // wait is escapable — this is inference, it takes as long as any other,
        // and it happens to someone who has already asked to leave.
        $this->lineEditor->saveHistory();

        if ($this->extractFacts && !$forgotten) {
            $this->chat->showInfo('Extracting the lasting facts... (Ctrl+C to skip)');
            $this->interrupt->beginTurn();
            try {
                $this->chat->showInfo($this->factExtractor->extract($bag)->summary());
            } finally {
                $this->interrupt->endTurn();
            }
        }

        $this->mcp->shutdown();

        if ($this->usage->requests() > 0) {
            echo Terminal::GRAY . "\n" . $this->usage->summary() . "\n" . Terminal::RESET;
        }

        echo Terminal::GRAY . "\nSession over. Good luck!\n" . Terminal::RESET;
        return Command::SUCCESS;
    }

    /**
     * Keep the conversation on disk. Said once if it cannot be — a session that
     * cannot be saved still works, but whoever counts on /resume must know.
     */
    private function recordSession(MessageBag $bag): void
    {
        if ($this->commands->saveSession($bag) || $bag->conversationSize() === 0 || $this->warnedAboutSessions) {
            return;
        }

        $this->warnedAboutSessions = true;
        $this->chat->showError(
            'Cannot save the conversation in ' . ($this->sessions->directory() ?? '?')
            . ': it will not be resumable.'
        );
    }

    /**
     * The session as data, when SHERPA_TRANSCRIPT names a file: every message
     * and what the session consumed, for reading a session afterwards and for
     * the benchmark. Off unless asked — it holds the code the session read.
     */
    private function writeTranscript(MessageBag $bag): void
    {
        $path = $_SERVER['SHERPA_TRANSCRIPT'] ?? getenv('SHERPA_TRANSCRIPT');
        if (!is_string($path) || trim($path) === '') {
            return;
        }

        $data = [
            'backend'  => $this->platform->backend()->value,
            'model'    => $this->platform->modelName(),
            'messages' => $bag->all(),
            'usage'    => [
                'requests'   => $this->usage->requests(),
                'prompt'     => $this->usage->prompt(),
                'completion' => $this->usage->completion(),
                'cached'     => $this->usage->cached(),
                'cost'       => $this->usage->cost(),
            ],
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false || @file_put_contents($path, $json) === false) {
            $this->chat->showError("Cannot write the transcript to {$path}");
        }
    }

    /**
     * Say so, once, when the graphics card is not actually being used: a window
     * that no longer fits beside the weights makes Ollama spill onto the CPU
     * rather than refuse, and everything still works, ten to a hundred times
     * slower, with nothing saying why. One request answers it.
     */
    private function reportResidency(): void
    {
        $residency = $this->platform->residency();

        if ($residency === null || !$residency->isLoaded() || $residency->isFullyOnGpu()) {
            return;
        }

        if ($residency->isCpuOnly()) {
            // Legitimate on a machine with no card at all, so this is reported
            // rather than warned about: it is the expected state there, and
            // only surprising on a machine bought for the other one.
            $this->chat->showInfo(
                'The model runs entirely on the processor. '
                . 'Expected with no graphics card; otherwise it could not be loaded onto it.'
            );

            return;
        }

        $this->chat->showInfo(sprintf(
            'The model spills onto the processor (%s). The window asked for (%s tokens) takes '
            . 'VRAM on top of the weights: bring it down with /model so that it all fits on the card.',
            $residency->describe(),
            number_format($this->budget->contextWindow(), 0, '.', ','),
        ));
    }

    /**
     * The project for the directory Sherpa was launched from: known by its path
     * (a subdirectory included), it opens at once; new, it is asked about in a
     * terminal and not in a pipe, where the message meant for the model would
     * answer. Nothing is written until the first message.
     */
    private function openProject(): bool
    {
        $launched = getenv('SHERPA_CWD');
        $directory = is_string($launched) && $launched !== '' ? $launched : (string) getcwd();
        $home = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');

        $refusal = WorkingProject::refusal($directory, $home);
        if ($refusal !== null) {
            echo Terminal::RED . '✗ ' . $refusal . "\n" . Terminal::RESET;

            return false;
        }

        $known = $this->projectStore->forDirectory($directory);
        if ($known !== null) {
            $this->working->open($known);

            return true;
        }

        $path = ProjectStore::canonical($directory);
        $this->working->hold($this->lineEditor->isInteractive()
            ? $this->questions->ask($path)
            : $this->projectStore->draft($path, basename($path), new DockerConfig(enabled: false)));

        return true;
    }

    private function renderStatus(Project $project, MessageBag $bag): void
    {
        $this->statusBar->render(
            $project,
            $this->platform->modelName(),
            $this->budget->estimateRequest($bag->all(), $this->toolbox->schemas()),
            $this->budget->compactThreshold(),
            $this->budget->isCalibrated(),
            $this->usage->statusLabel(),
        );
    }


    /** Whether $name is one of Sherpa's own commands, which a skill of that name does not shadow. */
    private function isCommand(string $name): bool
    {
        foreach (SlashCommands::catalogue() as $entry) {
            if ($entry['name'] === $name) {
                return true;
            }
        }

        return $name === '/quit';
    }

    /**
     * "/<skill> [request]" as the message the model gets: the skill's text,
     * then what was asked. Null when the word is no skill, or is a command.
     */
    private function skillMessage(string $input): ?string
    {
        [$word, $request] = array_pad(explode(' ', $input, 2), 2, '');
        if ($this->isCommand($word)) {
            return null;
        }

        $skill = $this->skillRegistry->get(substr($word, 1));
        if ($skill === null) {
            return null;
        }

        $request = trim($request);

        return "Apply the skill \"{$skill->name}\" below"
            . ($request !== '' ? " to this request:\n\n{$request}" : ' to the current work.')
            . "\n\n<skill name=\"{$skill->name}\">\n" . trim($skill->content) . "\n</skill>";
    }

    private function readUserInput(): ?string
    {
        return $this->lineEditor->prompt('❯ ', Terminal::BOLD . Terminal::GREEN);
    }

}
