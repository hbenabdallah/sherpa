<?php

namespace App\Mcp;

use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Agent\Tool\ToolDefinition;

/**
 * Brings the configured MCP servers into the session: their tools and a reader
 * for their resources into the Toolbox, their prompts as slash commands.
 *
 * Every server here is a third-party process that Sherpa did not write and
 * cannot vouch for. One that will not start, or answers nonsense, costs its own
 * tools and nothing else: the session continues, and says which server failed
 * and why rather than appearing to have fewer tools than it was told about.
 */
class McpRegistry
{
    /** @var array<string, McpClient> */
    private array $clients = [];

    /** @var array<int, string> one human-readable line per server */
    private array $report = [];

    /** @var array<string, array<int, array{uri: string, name: string, description: string, mimeType: string}>> by server */
    private array $resources = [];

    /** @var array<string, array<int, array{name: string, description: string, arguments: array<int, array{name: string, description: string, required: bool}>}>> by server */
    private array $prompts = [];

    /**
     * How much of the resource list goes into the reader's description: it is
     * sent with every request, so a server with hundreds is summarised.
     */
    private const LISTED_RESOURCES = 20;

    private bool $loaded = false;

    public function __construct(
        private readonly McpConfig $config,
        private readonly float $handshakeTimeout = 15.0,
        private readonly float $callTimeout = 120.0,
    ) {}

    public function load(Toolbox $toolbox): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        try {
            $servers = $this->config->servers();
        } catch (McpException $e) {
            $this->report[] = $e->getMessage();

            return;
        }

        foreach ($servers as $server) {
            if (!$server->enabled) {
                $this->report[] = "{$server->name}: disabled";
                continue;
            }

            try {
                $this->loadServer($server, $toolbox);
            } catch (McpException $e) {
                // Started but unusable: shut the process down rather than leave
                // it running for a session that will never talk to it again.
                $this->clients[$server->name]?->close();
                unset($this->clients[$server->name]);

                $this->report[] = $e->getMessage();
            }
        }
    }

    private function loadServer(ServerConfig $server, Toolbox $toolbox): void
    {
        $client = McpClient::for($server, $this->handshakeTimeout, $this->callTimeout);
        $this->clients[$server->name] = $client;

        $tools = $client->listTools();
        $added = 0;
        $skipped = [];

        foreach ($tools as $tool) {
            $name = $this->localName($server->name, $tool['name']);

            try {
                $toolbox->register(new ToolDefinition(
                    name: $name,
                    description: $this->describe($server->name, $tool['description']),
                    parameters: $this->parameters($tool['schema']),
                    // Third-party code reached over a pipe. Whatever a server
                    // says its tool does, the user is asked before it runs.
                    permission: Permission::CONFIRM,
                    handler: new McpTool($client, $tool['name']),
                ));
                $added++;
            } catch (\LogicException) {
                $skipped[] = $tool['name'];
            }
        }

        $resources = $client->listResources();
        if ($resources !== []) {
            try {
                $toolbox->register(new ToolDefinition(
                    name: $this->localName($server->name, 'read_resource'),
                    description: $this->describeResources($server->name, $resources),
                    parameters: ['uri' => ['type' => 'string', 'description' => 'The URI of the resource, as listed', 'required' => true]],
                    // What comes back is third-party content, like a tool's.
                    permission: Permission::CONFIRM,
                    handler: new McpResourceReader($client),
                ));
                $this->resources[$server->name] = $resources;
            } catch (\LogicException) {
                $skipped[] = 'read_resource';
            }
        }

        $prompts = $client->listPrompts();
        if ($prompts !== []) {
            $this->prompts[$server->name] = $prompts;
        }

        $line = "{$server->name}: " . $this->count($added, 'tool')
            . ($resources !== [] ? ', ' . $this->count(count($resources), 'resource') : '')
            . ($prompts !== [] ? ', ' . $this->count(count($prompts), 'prompt') : '')
            . ' · ' . $this->origin($server);
        if ($skipped !== []) {
            $line .= ', ' . count($skipped) . ' skipped (name already taken: ' . implode(', ', $skipped) . ')';
        }

        $this->report[] = $line;
    }

    /**
     * Where a server runs, said out loud.
     *
     * A local process sees what it is given and nothing leaves the machine; a
     * server reached over HTTP receives every argument the model sends it. That
     * is the user's choice to make, and it is made once in a JSON file — so the
     * session says which is which rather than letting both look alike.
     */
    private function origin(ServerConfig $server): string
    {
        if (!$server->isHttp()) {
            return 'local process';
        }

        return 'online · ' . (parse_url($server->url, PHP_URL_HOST) ?: $server->url);
    }

    /**
     * Namespaced, because a server is free to call its tool file_read and the
     * agent's own file_read must keep meaning what it says.
     */
    private function localName(string $server, string $tool): string
    {
        $clean = fn(string $s) => trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($s)) ?? '', '_');

        return 'mcp__' . $clean($server) . '__' . $clean($tool);
    }

    private function describe(string $server, string $description): string
    {
        $description = trim($description);

        return "[MCP · {$server}] " . ($description === '' ? 'No description provided.' : $description);
    }

    /**
     * JSON Schema as the server sends it, in the shape ToolDefinition expects.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, array{type: string, description: string, required: bool}>
     */
    private function parameters(array $schema): array
    {
        $required = array_map(strval(...), is_array($schema['required'] ?? null) ? $schema['required'] : []);
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        $parameters = [];

        foreach ($properties as $name => $spec) {
            $spec = is_array($spec) ? $spec : [];

            $parameters[(string) $name] = [
                'type'        => $this->type($spec['type'] ?? 'string'),
                'description' => (string) ($spec['description'] ?? ''),
                'required'    => in_array((string) $name, $required, true),
            ];
        }

        return $parameters;
    }

    /** A nullable field is declared as ["string", "null"]; the first real one wins. */
    private function type(mixed $type): string
    {
        if (is_array($type)) {
            $type = array_values(array_filter($type, fn($t) => $t !== 'null'))[0] ?? 'string';
        }

        return in_array($type, ['string', 'integer', 'number', 'boolean', 'array', 'object'], true)
            ? (string) $type
            : 'string';
    }

    private function count(int $n, string $noun): string
    {
        return $n . ' ' . $noun . ($n > 1 ? 's' : '');
    }

    /**
     * The reader's description is where the model learns what there is to
     * read, so it lists the resources, up to a point.
     *
     * @param array<int, array{uri: string, name: string, description: string, mimeType: string}> $resources
     */
    private function describeResources(string $server, array $resources): string
    {
        $lines = [];
        foreach (array_slice($resources, 0, self::LISTED_RESOURCES) as $resource) {
            $about = trim($resource['name'] . ($resource['description'] !== '' ? ': ' . $resource['description'] : ''));
            $lines[] = '- ' . $resource['uri'] . ($about !== '' ? ' — ' . mb_strimwidth($about, 0, 120, '…') : '');
        }

        $more = count($resources) - self::LISTED_RESOURCES;
        if ($more > 0) {
            $lines[] = "- and {$more} more";
        }

        return "[MCP · {$server}] Read one of this server's resources by its URI. Available:\n" . implode("\n", $lines);
    }

    /**
     * Every resource, by server, for /mcp.
     *
     * @return array<string, array<int, array{uri: string, name: string, description: string, mimeType: string}>>
     */
    public function resources(): array
    {
        return $this->resources;
    }

    /**
     * Every prompt, as the slash command that runs it: /<server>:<prompt>.
     *
     * @return array<int, array{command: string, server: string, name: string, description: string, arguments: array<int, array{name: string, description: string, required: bool}>}>
     */
    public function promptCommands(): array
    {
        $commands = [];
        foreach ($this->prompts as $server => $prompts) {
            foreach ($prompts as $prompt) {
                $commands[] = ['command' => '/' . $server . ':' . $prompt['name'], 'server' => $server] + $prompt;
            }
        }

        return $commands;
    }

    /**
     * "/<server>:<prompt> [arguments]" as the message the model gets, or null
     * when the word names no prompt.
     *
     * Arguments are given as name=value; what is left over goes to the first
     * argument not given that way, so a prompt with one argument takes the
     * whole line as it is typed.
     *
     * @throws McpException when a required argument is missing, or the server fails
     */
    public function expandPrompt(string $input): ?string
    {
        [$word, $rest] = array_pad(explode(' ', trim($input), 2), 2, '');

        foreach ($this->promptCommands() as $prompt) {
            if ($prompt['command'] !== $word) {
                continue;
            }

            $arguments = PromptArguments::parse($rest, $prompt['arguments']);

            $missing = array_values(array_filter(
                $prompt['arguments'],
                fn(array $argument) => $argument['required'] && ($arguments[$argument['name']] ?? '') === '',
            ));
            if ($missing !== []) {
                throw new McpException("{$word}: missing " . implode(', ', array_column($missing, 'name'))
                    . '. Usage: ' . $word . ' ' . PromptArguments::usage($prompt['arguments']));
            }

            $messages = $this->clients[$prompt['server']]->getPrompt($prompt['name'], $arguments);

            return PromptArguments::asMessage($messages);
        }

        return null;
    }

    /** @return array<int, string> */
    public function report(): array
    {
        return $this->report;
    }

    public function hasServers(): bool
    {
        return $this->report !== [];
    }

    public function shutdown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }

        $this->clients = [];
    }
}
