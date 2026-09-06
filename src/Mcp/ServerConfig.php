<?php

namespace App\Mcp;

/**
 * One MCP server as the user declared it.
 *
 * The shape follows the mcpServers convention other MCP clients already use,
 * so a config can be copied across rather than rewritten.
 */
final class ServerConfig
{
    /**
     * @param array<int, string>    $args
     * @param array<string, string> $env
     */
    public function __construct(
        public readonly string $name,
        public readonly string $command,
        public readonly array $args = [],
        public readonly array $env = [],
        public readonly bool $enabled = true,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(string $name, array $data): self
    {
        $command = $data['command'] ?? null;

        if (!is_string($command) || trim($command) === '') {
            throw new McpException("Serveur MCP « {$name} » : 'command' manquant ou vide.");
        }

        return new self(
            name: $name,
            command: $command,
            args: array_values(array_map(strval(...), $data['args'] ?? [])),
            env: array_map(strval(...), $data['env'] ?? []),
            // Disabled entries stay in the file so a server can be turned off
            // without losing how it was set up.
            enabled: (bool) ($data['enabled'] ?? true),
        );
    }
}
