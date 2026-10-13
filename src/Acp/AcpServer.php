<?php

namespace App\Acp;

use App\Agent\AgentLoop;
use App\Agent\ContextBudget;
use App\Agent\MessageBag;
use App\Agent\PlanMode;
use App\Agent\Result;
use App\Agent\SystemPromptBuilder;
use App\Agent\TodoList;
use App\Agent\Tool\Toolbox;
use App\Command\SlashCommands;
use App\Mcp\McpException;
use App\Mcp\McpRegistry;
use App\Mcp\ServerConfig;
use App\Memory\ContextStore;
use App\Permission\SessionPermissions;
use App\Platform\SwitchablePlatform;
use App\Project\DockerConfig;
use App\Project\ProjectStore;
use App\Project\WorkingProject;
use App\Runtime\Frontend;
use App\Runtime\Interrupt;
use App\Session\SessionStore;
use App\Skills\SkillRegistry;
use App\Tool\Edit\FileSaver;
use App\Tool\Edit\Files;
use App\Usage\TokenCapReached;
use App\Usage\UsageMeter;
use App\Version;

/**
 * Sherpa as an ACP agent: an editor starts it, opens sessions in a project
 * directory, and sends prompts; each prompt is one turn of the same agent the
 * terminal runs, shown in the editor and answered from it.
 *
 * One turn at a time, in the order the editor asks. The services behind a
 * turn hold one project and one conversation, so a session is made current
 * before its turn — its project opened, its conversation put back — and set
 * aside after.
 */
final class AcpServer
{
    private const PROTOCOL_VERSION = 1;

    /**
     * Setting Sherpa up is answering questions in a terminal — the provider,
     * its key, the model — which an editor runs for its user: ACP's terminal
     * authentication. The editor appends the arguments to the command it
     * starts Sherpa with, `sherpa acp`.
     */
    private const SETUP = [
        'id'          => 'setup',
        'name'        => 'Set up Sherpa',
        'description' => 'Choose the model provider, its key and the model, in a terminal',
        'type'        => 'terminal',
        'args'        => ['--setup'],
    ];

    private Connection $connection;
    private EditorFrontend $editor;

    /** Why no session can open: the model cannot be reached. Null when it can. */
    private ?string $unavailable = null;

    /** Unavailable because nothing is set up yet: the editor can offer to. */
    private bool $needsSetup = false;

    /** @var array<string, mixed> what the editor said it can do */
    private array $client = [];

    /** @var array<string, AcpSession> */
    private array $open = [];
    private ?string $current = null;
    private ?string $directory = null;

    /** The session whose turn is running: a cancel for it is heard. */
    private ?string $running = null;

    private bool $configuredServers = false;
    private bool $warnedAboutSaving = false;

    public function __construct(
        private readonly AgentLoop $agent,
        private readonly Frontend $frontend,
        private readonly Interrupt $interrupt,
        private readonly WorkingProject $working,
        private readonly ProjectStore $projects,
        private readonly SystemPromptBuilder $prompts,
        private readonly SkillRegistry $skills,
        private readonly McpRegistry $mcp,
        private readonly Toolbox $toolbox,
        private readonly SessionStore $sessions,
        private readonly ContextStore $excerpts,
        private readonly TodoList $todos,
        private readonly PlanMode $planMode,
        private readonly SwitchablePlatform $platform,
        private readonly ContextBudget $budget,
        private readonly UsageMeter $usage,
        private readonly Files $files,
        private readonly SessionPermissions $grants,
    ) {}

    /** Answer the editor until it closes its end. */
    public function serve(Connection $connection, EditorFrontend $editor, ?string $unavailable, bool $needsSetup = false): void
    {
        $this->connection = $connection;
        $this->editor = $editor;
        $this->unavailable = $unavailable;
        $this->needsSetup = $needsSetup;

        $this->frontend->use($editor, $editor);
        $connection->intercept($this->intercept(...));
        // A turn reads nothing by itself: through the interrupt, every wait
        // it already checks takes in what the editor sent meanwhile.
        $this->interrupt->listenWith($connection->pump(...));

        while (($message = $connection->receive()) !== null) {
            $this->dispatch($message);
        }

        if ($this->current !== null) {
            $this->save($this->open[$this->current]);
        }
    }

    /**
     * What cannot wait for the turn to end: a cancel. Everything else queues.
     *
     * @param array<string, mixed> $message
     */
    private function intercept(array $message): bool
    {
        if ($message['method'] === 'session/cancel') {
            $session = $message['params']['sessionId'] ?? null;
            if ($this->running !== null && $session === $this->running) {
                $this->interrupt->request();
            }

            return true;
        }

        // The editor giving up on a request of its own: the turn ends by
        // session/cancel, which an editor sends as well.
        return $message['method'] === '$/cancel_request';
    }

    /** @param array<string, mixed> $message */
    private function dispatch(array $message): void
    {
        $method = (string) $message['method'];
        $isRequest = array_key_exists('id', $message) && (is_int($message['id']) || is_string($message['id']));
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        try {
            $result = match ($method) {
                'initialize'     => $this->initialize($params),
                'authenticate'   => [],
                'session/new'    => $this->newSession($params),
                'session/load'   => $this->loadSession($params),
                'session/prompt' => $this->prompt($params),
                default          => throw new AcpError("Unknown method: {$method}", AcpError::METHOD_NOT_FOUND),
            };

            if ($isRequest) {
                $this->connection->respond($message['id'], $result);
            }
        } catch (AcpError $e) {
            if ($isRequest) {
                $this->connection->fail($message['id'], $e);
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "sherpa acp: {$method}: " . $e->getMessage() . "\n");
            if ($isRequest) {
                $this->connection->fail($message['id'], new AcpError($e->getMessage()));
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $this->client = is_array($params['clientCapabilities'] ?? null) ? $params['clientCapabilities'] : [];

        // Files through the editor where it offers: what the user sees in a
        // tab, saved or not, is what the model reads and edits.
        $fs = is_array($this->client['fs'] ?? null) ? $this->client['fs'] : [];
        $this->files->through(
            ($fs['readTextFile'] ?? false) === true ? $this->readFromEditor(...) : null,
            ($fs['writeTextFile'] ?? false) === true ? $this->writeThroughEditor(...) : null,
        );

        return [
            'protocolVersion'   => self::PROTOCOL_VERSION,
            'agentCapabilities' => [
                'loadSession'        => true,
                'promptCapabilities' => ['image' => false, 'audio' => false, 'embeddedContext' => true],
                'mcpCapabilities'    => ['http' => true, 'sse' => false],
            ],
            'agentInfo'   => ['name' => 'sherpa', 'title' => 'Sherpa', 'version' => Version::current()],
            'authMethods' => $this->setupMethods(),
        ];
    }

    /**
     * The setup, for an editor that can run it in a terminal. Said both ways
     * editors understand: ACP's `type: terminal`, and the `_meta` form that
     * came before it, which names the whole command.
     *
     * @return list<array<string, mixed>>
     */
    private function setupMethods(): array
    {
        $terminal = ($this->client['auth']['terminal'] ?? false) === true;
        $meta = ($this->client['_meta']['terminal-auth'] ?? false) === true;
        if (!$terminal && !$meta) {
            return [];
        }

        $method = self::SETUP;
        if ($meta) {
            // The single executable is its own program; a checkout is PHP running bin/sherpa.
            $argv = array_values(array_map('strval', (array) ($_SERVER['argv'] ?? [])));
            $program = \Phar::running(false);
            $args = $program !== '' ? array_slice($argv, 1) : [(string) realpath($argv[0] ?? ''), ...array_slice($argv, 1)];

            $method['_meta'] = ['terminal-auth' => [
                'command' => $program !== '' ? $program : PHP_BINARY,
                'args'    => [...$args, '--setup'],
                'label'   => 'Sherpa setup',
            ]];
        }

        return [$method];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function newSession(array $params): array
    {
        $directory = $this->directoryOf($params['cwd'] ?? null);
        $this->assertAvailable();

        $this->setAside();
        $this->openProject($directory);
        $this->connectServers($params['mcpServers'] ?? []);

        $id = $this->sessions->reserve();
        $bag = new MessageBag();
        $bag->system($this->prompts->build($this->working->project()));

        $session = new AcpSession($id, $this->working->project()->path, $bag);
        $session->cursor = $this->sessions->cursor();
        $this->open[$id] = $session;
        $this->makeCurrent($session);

        return ['sessionId' => $id];
    }

    /**
     * A conversation saved earlier — in the terminal or in an editor — picked
     * back up: replayed to the editor, then continued.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function loadSession(array $params): array
    {
        $id = is_string($params['sessionId'] ?? null) ? $params['sessionId'] : '';
        $directory = $this->directoryOf($params['cwd'] ?? null);
        $this->assertAvailable();

        $this->setAside();
        $this->openProject($directory);
        $this->connectServers($params['mcpServers'] ?? []);

        $saved = $this->sessions->load($id) ?? throw new AcpError("No conversation {$id} in this project.", AcpError::RESOURCE_NOT_FOUND);

        $bag = new MessageBag();
        $bag->system($this->prompts->build($this->working->project()) . SlashCommands::resumeNote($saved));
        foreach ($saved->messages as $message) {
            $bag->add($message);
        }

        $this->sessions->adopt($saved);
        $session = new AcpSession($id, $this->working->project()->path, $bag);
        $session->cursor = $this->sessions->cursor();
        $session->excerpts = $saved->excerpts;
        $this->open[$id] = $session;
        $this->makeCurrent($session);

        (new Replay($this->connection, $id))->send($saved->messages);

        return [];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function prompt(array $params): array
    {
        $id = is_string($params['sessionId'] ?? null) ? $params['sessionId'] : '';
        $session = $this->open[$id] ?? throw new AcpError("No session {$id}: open one with session/new.", AcpError::RESOURCE_NOT_FOUND);

        $text = PromptText::from(is_array($params['prompt'] ?? null) ? $params['prompt'] : [], $session->directory);
        if (trim($text) === '') {
            throw AcpError::invalidParams('The prompt is empty.');
        }

        $this->makeCurrent($session);

        // The first message is the decision to work here, as in the terminal.
        // Saving the project rebinds where conversations go: this one keeps
        // the name the editor already has.
        $cursor = $this->sessions->cursor();
        if ($this->working->save()) {
            $this->sessions->resume($cursor);
        }

        $session->bag->user($this->planMode->frame($text));
        $this->editor->forSession($id, is_array($this->client['session']['notices'] ?? null));

        $this->running = $id;
        $this->interrupt->beginTurn();

        try {
            $stop = $this->stopReason($this->agent->run($session->bag));
        } catch (TokenCapReached $e) {
            $this->editor->showError($e->getMessage());
            $stop = 'max_tokens';
        } catch (\Throwable $e) {
            // An aborted request often surfaces as an exception; a cancel is
            // not an error, and must not be shown as one.
            if (!$this->interrupt->requested()) {
                throw $e;
            }
            $stop = 'cancelled';
        } finally {
            $this->interrupt->endTurn();
            $this->running = null;
            $this->save($session);
        }

        $this->reportUsage($session);

        return ['stopReason' => $stop];
    }

    /** How the turn ended, as ACP names it — and said, when the reply did not say it. */
    private function stopReason(Result $result): string
    {
        if ($result->interrupted) {
            return 'cancelled';
        }

        if ($result->repeatedError !== null) {
            $this->editor->showError("Every call came back with the same error; turn stopped:\n{$result->repeatedError}\n"
                . 'It is likely something only you can fix. Once it is, ask again.');
        } elseif ($result->repetitionDetected && !$result->repliedBeforeStop) {
            $this->editor->showError('The model kept repeating the same tool call without getting anywhere; turn stopped. '
                . 'Rephrase, or give it what it is missing.');
        } elseif ($result->maxIterationsReached) {
            $this->editor->showError('Iteration limit reached.');

            return 'max_turn_requests';
        }

        return 'end_turn';
    }

    private function reportUsage(AcpSession $session): void
    {
        $update = [
            'sessionUpdate' => 'usage_update',
            'used'          => $this->budget->estimateRequest($session->bag->all(), $this->toolbox->schemas()),
            'size'          => $this->budget->contextWindow(),
        ];

        $cost = $this->usage->cost();
        if ($cost !== null) {
            $update['cost'] = ['amount' => round($cost, 6), 'currency' => 'USD'];
        }

        $this->connection->notify('session/update', ['sessionId' => $session->id, 'update' => $update]);
    }

    /** The editor's text of a file — unsaved changes included — or null to read it from disk. */
    private function readFromEditor(string $path): ?string
    {
        $session = $this->running ?? $this->current;
        if ($session === null) {
            return null;
        }

        try {
            $result = $this->connection->request('fs/read_text_file', ['sessionId' => $session, 'path' => $path]);
        } catch (AcpError) {
            return null;
        }

        return is_array($result) && is_string($result['content'] ?? null) ? $result['content'] : null;
    }

    /** @throws \RuntimeException */
    private function writeThroughEditor(string $path, string $content): void
    {
        $session = $this->running ?? $this->current;
        if ($session === null) {
            FileSaver::save($path, $path, $content);

            return;
        }

        try {
            $this->connection->request('fs/write_text_file', ['sessionId' => $session, 'path' => $path, 'content' => $content]);
        } catch (AcpError $e) {
            throw new \RuntimeException('the editor could not write ' . $this->relative($path) . ': ' . $e->getMessage());
        }
    }

    private function relative(string $path): string
    {
        $root = rtrim((string) $this->directory, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    // ---- one conversation at a time ---------------------------------------------------

    /** Put $session's conversation back into the services a turn uses. */
    private function makeCurrent(AcpSession $session): void
    {
        if ($this->current === $session->id) {
            return;
        }

        $this->setAside();
        $this->openProject($session->directory);

        $this->sessions->resume($session->cursor);
        $this->excerpts->import($session->excerpts);
        // The plan was another conversation's.
        $this->todos->clear();
        $session->planning ? $this->planMode->on() : $this->planMode->off();
        $this->grants->clear();
        foreach ($session->grants as $tool) {
            $this->grants->allowForSession($tool);
        }

        $this->current = $session->id;
    }

    /** Keep the current conversation's state with it, before another takes the services. */
    private function setAside(): void
    {
        $session = $this->current === null ? null : $this->open[$this->current] ?? null;
        if ($session === null) {
            return;
        }

        $session->cursor = $this->sessions->cursor();
        $session->excerpts = $this->excerpts->export();
        $session->planning = $this->planMode->isActive();
        $session->grants = $this->grants->all();
        $this->current = null;
    }

    private function save(AcpSession $session): void
    {
        if ($session->bag->conversationSize() === 0) {
            return;
        }

        $saved = $this->sessions->save(
            $session->bag->all(),
            $this->excerpts->export(),
            $this->platform->backend()->value,
            $this->platform->modelName(),
        );

        if (!$saved && !$this->warnedAboutSaving) {
            $this->warnedAboutSaving = true;
            $this->editor->showError('Cannot save the conversation in ' . ($this->sessions->directory() ?? '?') . ': it will not be loadable.');
        }
    }

    // ---- the project and its tools ----------------------------------------------------

    /** The project directory an editor names: absolute, existing, and not the whole disk. */
    private function directoryOf(mixed $cwd): string
    {
        if (!is_string($cwd) || !str_starts_with($cwd, '/')) {
            throw AcpError::invalidParams('cwd must be an absolute path.');
        }

        $refusal = WorkingProject::refusal($cwd, (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: ''));
        if ($refusal !== null) {
            throw AcpError::invalidParams($refusal);
        }

        return ProjectStore::canonical($cwd);
    }

    private function assertAvailable(): void
    {
        if ($this->unavailable !== null) {
            throw new AcpError($this->unavailable, $this->needsSetup ? AcpError::AUTH_REQUIRED : AcpError::INTERNAL_ERROR);
        }
    }

    /**
     * The project for $directory: the one Sherpa knows there, or a new one
     * held until the first message. Nothing is asked — an editor has no
     * terminal — so a new one runs its commands on this machine; `sherpa` in
     * a terminal sets up a container once, and the editor follows.
     */
    private function openProject(string $directory): void
    {
        if ($this->directory === $directory) {
            return;
        }

        $known = $this->projects->forDirectory($directory);
        if ($known !== null) {
            $this->working->open($known);
        } else {
            $this->working->hold($this->projects->draft($directory, basename($directory), new DockerConfig(enabled: false)));
        }

        $this->directory = $this->working->project()->path;
        $this->skills->setProjectPath($this->directory);
        $this->skills->load();
        $this->excerpts->open();
    }

    /**
     * The servers of mcp.json, once, and those the editor brings, by name: a
     * server already connected under that name is replaced.
     */
    private function connectServers(mixed $servers): void
    {
        if (!$this->configuredServers) {
            $this->configuredServers = true;
            $this->mcp->load($this->toolbox);
            foreach ($this->mcp->report() as $line) {
                fwrite(STDERR, "sherpa acp: MCP · {$line}\n");
            }
        }

        foreach (is_array($servers) ? $servers : [] as $server) {
            $config = self::serverConfig(is_array($server) ? $server : []);
            if ($config === null) {
                continue;
            }

            try {
                fwrite(STDERR, 'sherpa acp: MCP · ' . $this->mcp->connect($config, $this->toolbox) . "\n");
            } catch (McpException $e) {
                fwrite(STDERR, "sherpa acp: MCP · {$config->name}: " . $e->getMessage() . "\n");
            }
        }
    }

    /**
     * An editor's MCP server in mcp.json's terms. Null for one that cannot be
     * run: no name, nothing to start or reach, or SSE, which is not offered.
     *
     * @param array<string, mixed> $server
     */
    private static function serverConfig(array $server): ?ServerConfig
    {
        $name = is_string($server['name'] ?? null) ? $server['name'] : '';
        // A name mcp.json would take: it prefixes the server's tools.
        $name = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $name), '-');
        if (!ServerConfig::validName($name)) {
            return null;
        }

        $pairs = static function (mixed $list): array {
            $map = [];
            foreach (is_array($list) ? $list : [] as $pair) {
                if (is_array($pair) && is_string($pair['name'] ?? null) && is_scalar($pair['value'] ?? null)) {
                    $map[$pair['name']] = (string) $pair['value'];
                }
            }

            return $map;
        };

        $type = $server['type'] ?? 'stdio';
        if ($type === 'http') {
            $url = is_string($server['url'] ?? null) ? $server['url'] : '';

            return $url === '' ? null : new ServerConfig($name, url: $url, headers: $pairs($server['headers'] ?? []));
        }

        $command = is_string($server['command'] ?? null) ? $server['command'] : '';
        if ($type !== 'stdio' || $command === '') {
            return null;
        }

        $args = array_values(array_filter(is_array($server['args'] ?? null) ? $server['args'] : [], 'is_string'));

        return new ServerConfig($name, command: $command, args: $args, env: $pairs($server['env'] ?? []));
    }
}
