<?php

namespace App\Command;

use App\Acp\AcpServer;
use App\Acp\Connection;
use App\Acp\EditorFrontend;
use App\Agent\TodoList;
use App\Config\Backend;
use App\Config\ModelProfile;
use App\Config\ModelResolver;
use App\Config\ProviderName;
use App\Mcp\McpRegistry;
use App\Platform\SwitchablePlatform;
use App\Project\ProjectPathResolver;
use App\Tool\BackgroundJobs;
use App\Tool\Edit\EditPreview;
use App\Tool\ShellExecTool;
use App\TUI\Terminal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sherpa inside an editor: Zed, a JetBrains IDE, or any client of the Agent
 * Client Protocol starts `sherpa acp` and talks to it over stdin and stdout.
 * The model is the one the terminal uses, settled the same way; nothing is
 * asked, since there is nobody at a terminal to answer.
 */
#[AsCommand(name: 'acp', description: 'Run Sherpa for an editor, over the Agent Client Protocol (stdin and stdout)')]
class AcpCommand extends Command
{
    public function __construct(
        private readonly AcpServer $server,
        private readonly SwitchablePlatform $platform,
        private readonly ModelResolver $models,
        private readonly ModelSession $model,
        private readonly EditPreview $edits,
        private readonly ProjectPathResolver $paths,
        private readonly ShellExecTool $shell,
        private readonly TodoList $todos,
        private readonly McpRegistry $mcp,
        private readonly BackgroundJobs $jobs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('backend', 'b', InputOption::VALUE_REQUIRED, 'api (online model) or ollama (local model)')
            ->addOption('provider', 'P', InputOption::VALUE_REQUIRED, 'A recorded API provider (see /provider)')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Model to use, instead of the configured one')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Context window size, in tokens')
            // What an editor runs in a terminal when Sherpa says it is not set
            // up: ACP's terminal authentication.
            ->addOption('setup', null, InputOption::VALUE_NONE, 'Choose the provider, its key and the model, in a terminal, then return to the editor');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('setup')) {
            return self::setUp($this->model, $input) ? Command::SUCCESS : Command::FAILURE;
        }

        // Standard output carries the protocol and nothing else: a stray line
        // and the editor loses the framing. Everything Sherpa prints the way
        // it does in a terminal — and any PHP warning — goes to stderr, which
        // editors keep as the agent's log.
        $protocol = STDOUT;
        ini_set('display_errors', 'stderr');
        ob_start(static function (string $printed): string {
            if ($printed !== '') {
                fwrite(STDERR, Terminal::plain($printed));
            }

            return '';
        }, 1);

        // MCP servers and background jobs are child processes: not left
        // running behind an editor that closed, however it closed.
        register_shutdown_function(function (): void {
            $this->mcp->shutdown();
            $this->jobs->stopAll();
        });

        $unavailable = $this->settleModel($input);

        $connection = new Connection(STDIN, $protocol);
        $this->server->serve(
            $connection,
            new EditorFrontend($connection, $this->edits, $this->paths, $this->shell, $this->todos),
            $unavailable,
            needsSetup: $unavailable !== null && $this->notSetUp(),
        );

        return Command::SUCCESS;
    }

    /**
     * `--setup`, in the terminal an editor opened: the questions of a first
     * run, and nothing of a session after them.
     */
    public static function setUp(ModelSession $model, InputInterface $input): bool
    {
        if (!function_exists('posix_isatty') || !posix_isatty(STDIN)) {
            echo "sherpa acp --setup asks questions: run it in a terminal.\n";

            return false;
        }

        $flag = $input->getOption('backend');
        $ready = $model->setUp(is_string($flag) ? Backend::parse($flag) : null);

        echo $ready
            ? Terminal::GREEN . "\n✓ Sherpa is set up. Back to the editor: it can open a session now.\n" . Terminal::RESET
            : Terminal::RED . "\n✗ Not set up yet. Run sherpa acp --setup again once that is fixed.\n" . Terminal::RESET;

        return $ready;
    }

    /**
     * Unavailable because nothing was ever chosen — no address for the API,
     * no model — rather than because what was chosen does not answer: the
     * first is the editor's to offer setting up, the second is an error.
     */
    private function notSetUp(): bool
    {
        if ($this->platform->backend() === Backend::Api && $this->platform->apiEndpoint() === '') {
            return true;
        }

        return $this->platform->isAvailable() && !$this->model->profile() instanceof ModelProfile;
    }

    /**
     * The model, as the terminal settles it. What goes wrong is kept, and
     * said to the editor when it opens a session: its log is not where
     * anyone looks first.
     *
     * @return string|null why no session can open, or null
     */
    private function settleModel(InputInterface $input): ?string
    {
        ob_start();
        $ok = $this->tryModel($input);
        $said = trim(Terminal::plain((string) ob_get_clean()));

        echo $said === '' ? '' : $said . "\n";

        return $ok ? null : ($said !== '' ? $said : 'The model cannot be reached.');
    }

    private function tryModel(InputInterface $input): bool
    {
        $flag = $input->getOption('backend');
        $requested = is_string($flag) && trim($flag) !== '' ? Backend::parse($flag) : null;
        if (is_string($flag) && trim($flag) !== '' && !$requested instanceof Backend) {
            echo "Unknown backend: {$flag}. Possible values: api, ollama.\n";

            return false;
        }

        $provider = $input->getOption('provider');
        if (is_string($provider) && trim($provider) !== '') {
            $name = ProviderName::normalize($provider) ?? trim($provider);
            if (!in_array($name, array_keys($this->models->providers()), true)) {
                echo "Unknown provider: {$provider}. Record it first: start sherpa in a terminal and use /provider add.\n";

                return false;
            }

            $this->models->useProvider($name);
            $requested = Backend::Api;
        }

        $backend = $this->models->backend($requested);
        $this->platform->switchTo($backend);

        if ($backend === Backend::Api) {
            $this->model->configureApiEndpoint();
        }

        if (!$this->platform->isAvailable()) {
            $this->model->explainUnavailable($backend);

            return false;
        }

        $context = $input->getOption('context');
        $profile = $this->model->resolve(
            $input->getOption('model'),
            is_numeric($context) ? (int) $context : null,
            $backend,
            rememberAsDefault: false,
        );

        if (!$profile instanceof ModelProfile || $profile->model === '') {
            echo "No model chosen for the API. Set SHERPA_API_MODEL, pass -m <model> in the editor's agent settings, or run sherpa in a terminal once to choose.\n";

            return false;
        }

        $this->model->apply($profile);
        $this->model->announce($profile);
        $this->model->settleEmbeddings($backend);

        return true;
    }
}
