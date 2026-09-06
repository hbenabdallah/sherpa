<?php

namespace App\Command;

use App\Agent\AgentLoop;
use App\Agent\ContextBudget;
use App\Agent\HistoryCompactor;
use App\Agent\MessageBag;
use App\Agent\SystemPromptBuilder;
use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Config\ModelProfile;
use App\Config\ModelResolver;
use App\Mcp\McpRegistry;
use App\Memory\FactExtractor;
use App\Memory\MemoryStore;
use App\Permission\ProjectPermissions;
use App\Permission\SessionPermissions;
use App\Platform\PlatformInterface;
use App\Project\Project;
use App\Project\ProjectPathResolver;
use App\Project\ProjectStore;
use App\Runtime\Interrupt;
use App\Skills\SkillRegistry;
use App\Tool\ShellExecTool;
use App\TUI\ChatPane;
use App\TUI\LineEditor;
use App\TUI\ModelSelector;
use App\TUI\ProjectSelector;
use App\TUI\StatusBar;
use App\TUI\Terminal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sherpa', description: 'Sherpa — agent de développement PHP/Symfony')]
class SherpaCommand extends Command
{
    public function __construct(
        private readonly ProjectSelector $projectSelector,
        private readonly ProjectStore $projectStore,
        private readonly MemoryStore $memoryStore,
        private readonly FactExtractor $factExtractor,
        private readonly SkillRegistry $skillRegistry,
        private readonly AgentLoop $agentLoop,
        private readonly SystemPromptBuilder $promptBuilder,
        private readonly PlatformInterface $platform,
        private readonly ChatPane $chat,
        private readonly StatusBar $statusBar,
        private readonly Terminal $terminal,
        private readonly ProjectPathResolver $paths,
        private readonly ShellExecTool $shellExecTool,
        private readonly ContextBudget $budget,
        private readonly HistoryCompactor $compactor,
        private readonly Toolbox $toolbox,
        private readonly LineEditor $lineEditor,
        private readonly ProjectPermissions $projectPermissions,
        private readonly SessionPermissions $sessionPermissions,
        private readonly Interrupt $interrupt,
        private readonly McpRegistry $mcp,
        private readonly ModelResolver $models,
        private readonly ModelSelector $modelSelector,
    ) {
        parent::__construct();
    }

    /** The model in force. Set at boot, replaced by /model. */
    private ?ModelProfile $profile = null;

    protected function configure(): void
    {
        $this
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Project slug to open directly')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Modèle à utiliser, à la place de celui configuré')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Taille de la fenêtre de contexte, en tokens');
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

        $this->statusBar->renderWelcome();

        if (!$this->platform->isAvailable()) {
            $backend = $this->platform->name();
            echo Terminal::RED . "✗ {$backend} non accessible. Vérifiez que le service tourne et que la configuration pointe au bon endroit.\n" . Terminal::RESET;

            return Command::FAILURE;
        }

        // Which model, and how large a window. Asked once per machine, then
        // remembered — and asked before the project, because the answer belongs
        // to the machine rather than to what is being worked on.
        $profile = $this->resolveModel($input);
        if ($profile === null) {
            echo Terminal::GRAY . "Au revoir.\n" . Terminal::RESET;
            return Command::SUCCESS;
        }

        $this->applyModel($profile);
        echo Terminal::GREEN . '✓ Modèle : ' . Terminal::BOLD . $profile->model . Terminal::RESET
            . Terminal::GRAY . ' (' . number_format($profile->contextWindow, 0, ',', ' ') . ' tokens · '
            . $profile->source . ")\n" . Terminal::RESET;

        // Select or load project
        $project = $this->selectProject($input);
        if ($project === null) {
            echo Terminal::GRAY . "Au revoir.\n" . Terminal::RESET;
            return Command::SUCCESS;
        }

        // Bootstrap context for this project
        $this->memoryStore->open($project->memoryDb);
        $this->skillRegistry->setProjectPath($project->path);
        $this->skillRegistry->load();
        $this->projectStore->touch($project);

        // One shared resolver confines every filesystem tool to this project.
        $this->paths->setRoot($project->path);
        $this->projectPermissions->setProject($project);

        if ($project->docker->enabled && $project->docker->container !== '') {
            $this->shellExecTool->setDockerContainer($project->docker->container);
        }

        // Third-party tools join the toolbox here, before the first turn is
        // built, so their schemas are in the very first request.
        $this->mcp->load($this->toolbox);
        foreach ($this->mcp->report() as $line) {
            echo Terminal::GRAY . '  MCP · ' . Terminal::plain($line, singleLine: true) . "\n" . Terminal::RESET;
        }

        $bag = new MessageBag();
        $bag->system($this->promptBuilder->build($project));

        echo Terminal::GREEN . "✓ Projet chargé: " . Terminal::BOLD . $project->name . Terminal::RESET
            . Terminal::GRAY . " ({$project->stack})\n" . Terminal::RESET;
        echo Terminal::GRAY . "  /help  /model  /memory  /skills  /mcp  /reset  /exit\n" . Terminal::RESET;

        // A standing grant made in an earlier session runs unprompted from here
        // on. Say so on the way in rather than let it be discovered by surprise.
        $standing = $this->projectPermissions->all();
        if ($standing !== []) {
            echo Terminal::YELLOW . '  ⚠ Pré-autorisés sans confirmation : ' . implode(', ', $standing)
                . Terminal::GRAY . "  (/permissions pour révoquer)\n" . Terminal::RESET;
        }

        echo "\n";

        // Main REPL loop
        $warnedAboutConfig = false;

        while (true) {
            // The path, the container and the stack were read at startup and
            // this session has been running on them since. It cannot adopt a
            // change made out there, so it says so once and leaves the decision
            // to restart to the user.
            if (!$warnedAboutConfig && $this->projectStore->changedOnDisk()) {
                $warnedAboutConfig = true;
                $this->chat->showInfo(
                    'La configuration des projets a changé sur le disque. '
                    . 'Cette session garde celle qu\'elle a lue au démarrage — '
                    . 'redémarrez Sherpa pour prendre en compte un chemin, un conteneur ou une stack modifiés.'
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

            // Slash commands
            if (str_starts_with($userInput, '/')) {
                if ($this->handleSlashCommand($userInput, $bag, $project)) {
                    continue;
                }
                echo Terminal::RED . "Commande inconnue: {$userInput}\n" . Terminal::RESET;
                continue;
            }

            $bag->user($userInput);
            $this->chat->addUserMessage($userInput);

            $this->interrupt->beginTurn();

            try {
                $result = $this->agentLoop->run($bag);

                if ($result->interrupted) {
                    $this->chat->showInfo('Tour interrompu. La conversation est conservée — Ctrl+C à nouveau pour quitter.');
                } elseif ($result->maxIterationsReached) {
                    $this->chat->showError('Limite d\'itérations atteinte.');
                }
            } catch (\Throwable $e) {
                $this->chat->showError('Erreur: ' . $e->getMessage());
            } finally {
                $this->interrupt->endTurn();
            }
        }

        // End-of-session: extract and persist facts. Wrapped in a turn so the
        // wait is escapable — this is inference, it takes as long as any other,
        // and it happens to someone who has already asked to leave.
        $this->lineEditor->saveHistory();

        $this->chat->showInfo('Extraction des faits durables... (Ctrl+C pour passer)');
        $this->interrupt->beginTurn();
        try {
            $this->chat->showInfo($this->factExtractor->extract($bag)->summary());
        } finally {
            $this->interrupt->endTurn();
        }

        $this->mcp->shutdown();

        echo Terminal::GRAY . "\nSession terminée. Bonne continuation!\n" . Terminal::RESET;
        return Command::SUCCESS;
    }

    /**
     * Walk the precedence chain, and ask when it runs out.
     *
     * @return ModelProfile|null null only when someone chose to leave rather
     *                           than answer
     */
    private function resolveModel(InputInterface $input): ?ModelProfile
    {
        $context = $input->getOption('context');

        $explicit = $this->models->fromCommandLine(
            $input->getOption('model'),
            is_numeric($context) ? (int) $context : null,
        );

        if ($explicit !== null) {
            return $this->verified($explicit);
        }

        $stored = $this->models->stored();
        if ($stored !== null) {
            return $this->verified($stored);
        }

        // Nothing chosen on this machine yet. A menu is useless to a pipe, so a
        // scripted run takes the configured default and is told which one.
        if (!$this->lineEditor->isInteractive()) {
            $fallback = $this->models->fallback();

            echo Terminal::YELLOW . "⚠ Aucun modèle choisi pour cette machine ; « {$fallback->model} » utilisé.\n"
                . Terminal::GRAY . "  Lancez sherpa dans un terminal pour choisir, ou écrivez "
                . $this->models->configPath() . ".\n" . Terminal::RESET;

            return $fallback;
        }

        $chosen = $this->modelSelector->run(firstRun: true);
        if ($chosen === null) {
            return null;
        }

        // Said either way: a choice the user believes is permanent and is not
        // would be discovered only at the next launch, by being asked again.
        if ($this->models->remember($chosen)) {
            echo Terminal::GREEN . '✓ Choix enregistré dans ' . Terminal::RESET
                . Terminal::GRAY . $this->models->configPath() . "\n" . Terminal::RESET;
        } else {
            echo Terminal::YELLOW . '⚠ Impossible d\'écrire ' . $this->models->configPath()
                . " — le choix ne vaut que pour cette session.\n" . Terminal::RESET;
        }

        return $chosen;
    }

    /**
     * Check a profile that nobody looked at on the way in.
     *
     * A name from -m or from a hand-edited config.yaml has been through no
     * screen and no validation. Left alone, a typo costs a whole first turn
     * before failing, and a model without tool support costs an entire session
     * that looks like Sherpa is broken. /api/show answers in a few
     * milliseconds, which is nothing set against either.
     *
     * Warnings, not refusals: the server was reachable a moment ago, so an
     * unexpected answer here is more likely a model about to be pulled than a
     * reason to refuse to start. The one thing that is corrected outright is a
     * window past the model's ceiling — that is not a preference, it is a
     * number the backend would quietly reinterpret.
     */
    private function verified(ModelProfile $profile): ModelProfile
    {
        $info = $this->platform->describeModel($profile->model);

        if ($info === null) {
            echo Terminal::YELLOW . "⚠ « {$profile->model} » est inconnu de " . $this->platform->name() . ".\n" . Terminal::RESET;
            echo Terminal::GRAY . "  Récupérez-le :  ollama pull {$profile->model}\n"
                . "  Ou changez-en avec /model une fois la session ouverte.\n" . Terminal::RESET;

            return $profile;
        }

        if (!$info->supportsTools()) {
            echo Terminal::RED . "⚠ {$profile->model} ne sait pas appeler d'outils.\n" . Terminal::RESET;
            echo Terminal::GRAY . "  Sherpa ne pourra ni lire un fichier ni lancer une commande avec.\n"
                . "  /model pour en choisir un autre.\n" . Terminal::RESET;

            return $profile;
        }

        if ($info->contextLength !== null && $profile->contextWindow > $info->contextLength) {
            echo Terminal::YELLOW . '⚠ Fenêtre demandée (' . number_format($profile->contextWindow, 0, ',', ' ')
                . ') au-delà du plafond de ' . $profile->model . ' ; ramenée à '
                . number_format($info->contextLength, 0, ',', ' ') . ".\n" . Terminal::RESET;

            return $profile->withContext($info->contextLength);
        }

        return $profile;
    }

    /**
     * Bind a profile to the two services that hold a copy of it.
     *
     * They are set together, always, because a window belonging to one model
     * and a name belonging to another is a state neither of them can detect.
     */
    private function applyModel(ModelProfile $profile): void
    {
        $this->profile = $profile;
        $this->platform->useModel($profile->model, $profile->contextWindow);
        $this->budget->useModel($profile->model, $profile->contextWindow);
    }

    private function selectProject(InputInterface $input): ?Project
    {
        $slug = $input->getOption('project');

        if ($slug !== null) {
            $project = $this->projectStore->get($slug);
            if ($project === null) {
                echo Terminal::RED . "Projet '{$slug}' introuvable.\n" . Terminal::RESET;
                return null;
            }
            return $project;
        }

        // Launched from inside a project Sherpa already knows: open it. The
        // launcher passes the directory the user was actually standing in,
        // which the container cannot otherwise see.
        $cwd = getenv('SHERPA_CWD');
        if (is_string($cwd) && $cwd !== '') {
            $here = $this->projectStore->forDirectory($cwd);

            if ($here !== null) {
                echo Terminal::GRAY . "  Projet détecté depuis {$cwd}\n" . Terminal::RESET;

                return $here;
            }
        }

        if ($this->projectStore->isEmpty()) {
            echo Terminal::YELLOW . "Aucun projet configuré. Création du premier projet...\n\n" . Terminal::RESET;
        }

        return $this->projectSelector->run();
    }

    private function renderStatus(Project $project, MessageBag $bag): void
    {
        $this->statusBar->render(
            $project,
            $this->platform->modelName(),
            $this->budget->estimateRequest($bag->all(), $this->toolSchemas()),
            $this->budget->compactThreshold(),
            $this->budget->isCalibrated(),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function toolSchemas(): array
    {
        return array_map(fn($def) => $def->toFunctionSchema(), $this->toolbox->getDefinitions());
    }

    private function readUserInput(): ?string
    {
        return $this->lineEditor->prompt('❯ ', Terminal::BOLD . Terminal::GREEN);
    }

    private function handleSlashCommand(string $cmd, MessageBag $bag, Project $project): bool
    {
        $parts = explode(' ', $cmd, 2);
        $command = $parts[0];
        $args = $parts[1] ?? '';

        switch ($command) {
            case '/help':
                echo Terminal::CYAN . <<<HELP

  Commandes disponibles:
    /help               Affiche cette aide
    /memory             Liste les faits mémorisés
    /remember <k> <v>   Mémorise un fait manuellement
    /forget <key>       Supprime un fait
    /model              Change de modèle (liste, ou /model <nom>)
    /skills             Liste les skills disponibles
    /mcp                État des serveurs MCP configurés
    /permissions        Liste les autorisations permanentes du projet
    /permissions revoke <tool>   Révoque une autorisation
    /permissions clear  Révoque toutes les autorisations
    /context            Détaille l'occupation du contexte
    /compact            Compacte la conversation maintenant
    /reset              Réinitialise l'historique de la conversation
    /project            Affiche le projet courant
    /tools              Liste les tools disponibles
    /exit               Quitte Sherpa

HELP . Terminal::RESET;
                return true;

            case '/memory':
                $facts = $this->memoryStore->recall(limit: 30);
                if (empty($facts)) {
                    $this->chat->showInfo('Aucun fait mémorisé.');
                } else {
                    echo Terminal::BOLD . "\nFaits mémorisés:\n" . Terminal::RESET;
                    foreach ($facts as $fact) {
                        echo Terminal::YELLOW . "  • {$fact->key}: " . Terminal::RESET . $fact->value . "\n";
                    }
                }
                return true;

            case '/remember':
                $kv = explode(' ', $args, 2);
                if (count($kv) < 2) {
                    echo Terminal::RED . "Usage: /remember <key> <value>\n" . Terminal::RESET;
                } else {
                    $recorded = $this->memoryStore->remember($kv[0], $kv[1]);
                    echo Terminal::GREEN . match ($recorded) {
                        \App\Memory\Recorded::Created    => "✓ Mémorisé: {$kv[0]}\n",
                        \App\Memory\Recorded::Unchanged  => "✓ Déjà connu: {$kv[0]}\n",
                        \App\Memory\Recorded::Superseded => "✓ Corrigé: {$kv[0]}\n",
                    } . Terminal::RESET;
                }
                return true;

            case '/forget':
                if ($args === '') {
                    echo Terminal::RED . "Usage: /forget <key>\n" . Terminal::RESET;
                } elseif (($removed = $this->memoryStore->forget($args)) > 0) {
                    echo Terminal::GREEN . "✓ Oublié: {$args} ({$removed} version" . ($removed > 1 ? 's' : '') . ")\n" . Terminal::RESET;
                } else {
                    echo Terminal::RED . "Clé introuvable: {$args}\n" . Terminal::RESET;
                }
                return true;

            case '/model':
                $this->handleModel(trim($args), $bag, $project);
                return true;

            case '/skills':
                $skills = $this->skillRegistry->all();
                if (empty($skills)) {
                    $this->chat->showInfo('Aucun skill disponible. Ajoutez des .md dans ~/.config/sherpa/skills/');
                } else {
                    echo Terminal::BOLD . "\nSkills disponibles:\n" . Terminal::RESET;
                    foreach ($skills as $skill) {
                        echo Terminal::YELLOW . "  • {$skill->name}: " . Terminal::RESET . $skill->description . "\n";
                    }
                }
                return true;

            case '/permissions':
                $this->handlePermissions($args);
                return true;

            case '/mcp':
                $report = $this->mcp->report();
                if ($report === []) {
                    $this->chat->showInfo('Aucun serveur MCP configuré. Déclarez-les dans ~/.config/sherpa/mcp.json.');

                    return true;
                }

                echo Terminal::BOLD . "\nServeurs MCP:\n" . Terminal::RESET;
                foreach ($report as $line) {
                    echo Terminal::YELLOW . '  • ' . Terminal::RESET . Terminal::plain($line) . "\n";
                }

                return true;

            case '/tools':
                echo Terminal::BOLD . "\nTools disponibles:\n" . Terminal::RESET;
                foreach ($this->toolbox->getDefinitions() as $def) {
                    $colour = match ($def->permission) {
                        Permission::AUTO    => Terminal::GRAY,
                        Permission::CONFIRM => Terminal::YELLOW,
                        Permission::DENY    => Terminal::RED,
                    };
                    printf(
                        "  %s%-18s %-8s%s %s\n",
                        $colour,
                        $def->name,
                        $def->permission->name,
                        Terminal::RESET,
                        Terminal::GRAY . mb_substr($def->description, 0, 60) . Terminal::RESET,
                    );
                }
                return true;

            case '/context':
                $schemas = $this->toolSchemas();
                $estimated = $this->budget->estimateRequest($bag->all(), $schemas);
                echo Terminal::BOLD . "\nContexte:\n" . Terminal::RESET;
                printf("  %-22s %s\n", 'modèle', $this->platform->modelName());
                printf("  %-22s %s\n", 'origine du choix', $this->profile?->source ?? '(inconnue)');
                printf("  %-22s %d\n", 'messages', $bag->count());
                printf("  %-22s ~%d tokens\n", 'historique', $this->budget->estimateMessages($bag->all()));
                printf("  %-22s ~%d tokens\n", 'schémas des tools', $this->budget->estimateTools($schemas));
                printf("  %-22s ~%d tokens\n", 'total estimé', $estimated);
                printf("  %-22s %d tokens\n", 'seuil de compaction', $this->budget->compactThreshold());
                printf("  %-22s %d tokens\n", 'fenêtre du modèle', $this->budget->contextWindow());
                printf(
                    "  %-22s %s\n",
                    'dernière mesure',
                    $this->budget->lastMeasured() !== null
                        ? $this->budget->lastMeasured() . ' tokens (serveur)'
                        : '(pas encore mesuré)',
                );
                printf("  %-22s %.2f\n", 'chars/token calibré', $this->budget->charsPerToken());
                return true;

            case '/reset':
                // Drop everything, then rebuild the system message from scratch
                // so refreshed memory facts and skills are picked up.
                $bag->replace([]);
                $bag->system($this->promptBuilder->build($project));
                echo Terminal::GREEN . "✓ Historique réinitialisé.\n" . Terminal::RESET;
                return true;

            case '/project':
                echo Terminal::BOLD . "\nProjet courant:\n" . Terminal::RESET;
                echo Terminal::YELLOW . "  Nom:    " . Terminal::RESET . $project->name . "\n";
                echo Terminal::YELLOW . "  Chemin: " . Terminal::RESET . $project->path . "\n";
                echo Terminal::YELLOW . "  Stack:  " . Terminal::RESET . $project->stack . "\n";
                echo Terminal::YELLOW . "  Docker: " . Terminal::RESET . ($project->docker->enabled ? "oui ({$project->docker->container})" : "non") . "\n";
                return true;

            case '/compact':
                $this->chat->showInfo('Compaction de la conversation...');
                $before = $this->budget->estimateRequest($bag->all(), $this->toolSchemas());
                $actions = $this->compactor->compact($bag, $this->toolSchemas());
                $after = $this->budget->estimateRequest($bag->all(), $this->toolSchemas());

                if ($actions === []) {
                    $this->chat->showInfo('Rien à compacter.');
                } else {
                    printf(
                        "%s✓ %s : ~%d → ~%d tokens.%s\n",
                        Terminal::GREEN,
                        implode(', ', $actions),
                        $before,
                        $after,
                        Terminal::RESET,
                    );
                }
                return true;
        }

        return false;
    }

    /**
     * Change models without leaving the session.
     *
     * `/model` opens the same screen as the first run; `/model <nom>` takes a
     * name directly, including one that was never on the list. Both go through
     * the same two checks, because both can land on a model that cannot call
     * tools — which is not a degraded session, it is a session that can do
     * nothing at all.
     */
    private function handleModel(string $args, MessageBag $bag, Project $project): void
    {
        if ($args === '') {
            $chosen = $this->modelSelector->run($this->profile);

            if ($chosen === null) {
                $this->chat->showInfo('Modèle inchangé : ' . ($this->profile?->model ?? $this->platform->modelName()));

                return;
            }

            $this->switchTo($chosen, $bag, $project);

            return;
        }

        $info = $this->platform->describeModel($args);

        if ($info === null) {
            echo Terminal::RED . "Modèle inconnu de " . $this->platform->name() . " : {$args}\n" . Terminal::RESET;
            echo Terminal::GRAY . "  Récupérez-le :  ollama pull {$args}\n"
                . "  Ou /model sans argument pour choisir dans la liste.\n" . Terminal::RESET;

            return;
        }

        if (!$info->supportsTools()) {
            echo Terminal::RED . "{$info->name} ne sait pas appeler d'outils.\n" . Terminal::RESET;
            echo Terminal::GRAY . "  Sherpa ne pourrait ni lire un fichier ni lancer une commande avec.\n" . Terminal::RESET;

            return;
        }

        // Keep the window already tuned for this machine where the model is
        // unchanged; otherwise start from what this one can actually take.
        $profile = $this->profile !== null && $this->profile->model === $info->name
            ? $this->profile
            : ModelProfile::suggestFor($info, 'choisi ici');

        $this->switchTo($profile, $bag, $project);
    }

    /**
     * Apply a new profile mid-session.
     *
     * A different model starts from a clean slate. The conversation carried
     * over from the previous one is not neutral history: it holds tool calls
     * shaped by that model's template, replies in its voice, and a length
     * chosen for its window. Handing that to a model that has never seen it —
     * frequently a smaller one — is how a switch turns into an agent that
     * behaves oddly for the rest of the session, with nothing on screen
     * connecting the two.
     *
     * Retuning the window of the *same* model resets nothing: same tokenizer,
     * same template, the history is still its own.
     */
    private function switchTo(ModelProfile $profile, MessageBag $bag, Project $project): void
    {
        $previous = $this->profile;
        $changed = $previous === null || $previous->model !== $profile->model;

        // Asked before anything moves, so declining leaves the session exactly
        // as it was rather than half-switched: the platform on the new model
        // and the conversation still belonging to the old one.
        if ($changed && !$this->confirmReset($bag, $profile)) {
            echo Terminal::GRAY . '  Modèle inchangé : '
                . ($previous?->model ?? $this->platform->modelName())
                . ". La conversation est intacte.\n" . Terminal::RESET;

            return;
        }

        $this->applyModel($profile);

        echo Terminal::GREEN . '✓ Modèle : ' . Terminal::BOLD . $profile->model . Terminal::RESET
            . Terminal::GRAY . ' (' . number_format($profile->contextWindow, 0, ',', ' ') . " tokens)\n" . Terminal::RESET;

        if ($changed) {
            $this->resetForNewModel($bag, $project);
        } elseif ($this->budget->needsCompaction($bag->all(), $this->toolSchemas())) {
            // Same model, smaller window: the history survives the change and
            // may no longer fit in what is left of it.
            $this->chat->showInfo(
                'La conversation dépasse déjà le seuil de compaction de cette fenêtre : '
                . 'elle sera compactée avant le prochain envoi.'
            );
        }

        $answer = strtolower($this->lineEditor->ask(
            '  Retenir ce modèle pour cette machine? [o/N]: ',
            Terminal::BOLD,
        ));

        if (!in_array($answer, ['o', 'oui', 'y', 'yes'], true)) {
            echo Terminal::GRAY . "  Valable pour cette session seulement.\n" . Terminal::RESET;

            return;
        }

        echo $this->models->remember($profile)
            ? Terminal::GREEN . '  ✓ Enregistré dans ' . $this->models->configPath() . "\n" . Terminal::RESET
            : Terminal::YELLOW . '  ⚠ Impossible d\'écrire ' . $this->models->configPath() . "\n" . Terminal::RESET;
    }

    /**
     * Ask, but only when the answer could cost something.
     *
     * A model changed in the first seconds of a session discards nothing, and a
     * question there is pure friction on the very action the user just asked
     * for. A model changed thirty turns in discards thirty turns. The rule that
     * separates the two is simply whether the conversation has started, so that
     * is what decides whether anything is asked.
     *
     * Declining defaults to No, as it does everywhere else that something is
     * about to be destroyed: an accidental Enter must not be the keystroke that
     * loses an afternoon's conversation.
     */
    private function confirmReset(MessageBag $bag, ModelProfile $profile): bool
    {
        $atStake = $bag->conversationSize();

        if ($atStake === 0) {
            return true;
        }

        echo Terminal::YELLOW . "\n  ⚠ Passer à {$profile->model} repart de zéro : "
            . $atStake . ' message' . ($atStake > 1 ? 's' : '')
            . " de cette conversation seront écartés.\n" . Terminal::RESET;
        echo Terminal::GRAY . "    La mémoire du projet, les autorisations et les serveurs MCP ne bougent pas.\n"
            . Terminal::RESET;

        return in_array(
            strtolower($this->lineEditor->ask('  Continuer? [o/N]: ', Terminal::BOLD)),
            ['o', 'oui', 'y', 'yes'],
            true,
        );
    }

    /**
     * Everything the previous model left behind, cleared.
     *
     * Three things are session state belonging to a model, and all three go:
     * the conversation, the chars-per-token calibration (a property of the
     * tokenizer — ContextBudget::useModel has already dropped it), and the
     * token counts read back from the server.
     *
     * Three things are emphatically not, and stay: the project's memory, which
     * is what Sherpa knows about the code rather than about the model; the
     * standing permissions, which are decisions the user made about risk; and
     * the MCP servers, which are processes. Saying which is which matters —
     * "réinitialisé" next to a database of accumulated facts is alarming, and
     * being alarmed about the wrong thing is its own kind of failure.
     */
    private function resetForNewModel(MessageBag $bag, Project $project): void
    {
        // Everything else in Sherpa that discards something says how much — an
        // elided tool output names its own line count — and a conversation is
        // worth more than a tool result. "Conversation vidée" with no number is
        // the sentence a user reads twice, wondering what they just lost.
        $discarded = $bag->conversationSize();

        // Rebuilt rather than reused: it is regenerated for every /reset too,
        // and rebuilding picks up any fact memorised since the session opened.
        $bag->replace([]);
        $bag->system($this->promptBuilder->build($project));
        $this->chat->clear();

        $this->chat->showInfo(sprintf(
            'Session repartie à zéro pour ce modèle : %s, prompt système reconstruit, '
            . 'estimation du contexte réapprise sur les deux prochains tours.',
            $discarded === 0
                ? 'la conversation n\'avait pas commencé'
                : $discarded . ' message' . ($discarded > 1 ? 's écartés' : ' écarté'),
        ));

        echo Terminal::GRAY . '  Conservés : la mémoire du projet, les autorisations, les serveurs MCP.'
            . "\n" . Terminal::RESET;
    }

    /**
     * List and revoke the standing per-project grants. A permission you cannot
     * withdraw from inside the tool is one the user has to go edit YAML to undo,
     * which in practice means it never gets undone.
     */
    private function handlePermissions(string $args): void
    {
        $parts = explode(' ', trim($args), 2);
        $action = $parts[0] ?? '';
        $target = trim($parts[1] ?? '');

        if ($action === 'clear') {
            $count = $this->projectPermissions->revokeAll();
            echo $count === 0
                ? Terminal::GRAY . "  Aucune autorisation à révoquer.\n" . Terminal::RESET
                : Terminal::GREEN . "✓ {$count} autorisation(s) révoquée(s).\n" . Terminal::RESET;

            return;
        }

        if ($action === 'revoke') {
            if ($target === '') {
                echo Terminal::RED . "Usage: /permissions revoke <tool>\n" . Terminal::RESET;
            } elseif ($this->projectPermissions->revoke($target)) {
                echo Terminal::GREEN . "✓ Révoqué : {$target}. La confirmation sera redemandée.\n" . Terminal::RESET;
            } else {
                echo Terminal::RED . "Pas une autorisation permanente : {$target}\n" . Terminal::RESET;
            }

            return;
        }

        $standing = $this->projectPermissions->all();
        $session = $this->sessionPermissions->all();

        echo Terminal::BOLD . "\nAutorisations\n" . Terminal::RESET;

        echo Terminal::YELLOW . "  Permanentes (projet) " . Terminal::RESET;
        echo $standing === []
            ? Terminal::GRAY . "— aucune\n" . Terminal::RESET
            : "\n" . Terminal::RESET;
        foreach ($standing as $tool) {
            echo Terminal::YELLOW . "    • {$tool}\n" . Terminal::RESET;
        }

        echo Terminal::CYAN . "  Session uniquement " . Terminal::RESET;
        echo $session === []
            ? Terminal::GRAY . "— aucune\n" . Terminal::RESET
            : "\n" . Terminal::RESET;
        foreach ($session as $tool) {
            echo Terminal::CYAN . "    • {$tool}\n" . Terminal::RESET;
        }

        if ($standing !== []) {
            echo Terminal::GRAY . "\n  /permissions revoke <tool> · /permissions clear\n" . Terminal::RESET;
        }
    }


}
