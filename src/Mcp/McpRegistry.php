<?php

namespace App\Mcp;

use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Agent\Tool\ToolDefinition;

/**
 * Brings the configured MCP servers' tools into the Toolbox.
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
                $this->report[] = "{$server->name} : désactivé";
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

        $line = "{$server->name} : {$added} tool" . ($added > 1 ? 's' : '');
        if ($skipped !== []) {
            $line .= ', ' . count($skipped) . ' ignoré' . (count($skipped) > 1 ? 's' : '')
                . ' (nom déjà pris : ' . implode(', ', $skipped) . ')';
        }

        $this->report[] = $line;
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

        return "[MCP · {$server}] " . ($description === '' ? 'Aucune description fournie.' : $description);
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
