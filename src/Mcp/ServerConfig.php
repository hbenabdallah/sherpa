<?php

namespace App\Mcp;

/**
 * One MCP server as the user declared it.
 *
 * The shape follows the mcpServers convention other MCP clients already use,
 * so a config can be copied across rather than rewritten. Two ways to reach a
 * server, and the file says which by what it declares:
 *
 *   "command": "php", "args": [...]     — a child process, over its pipes
 *   "url": "https://exemple/mcp"        — someone else's server, over HTTP
 *
 * The second matters more here than it does elsewhere: Sherpa runs in a
 * container that holds nothing but PHP, so a server distributed as `npx` or
 * `uvx` cannot be started at all — while the same server reached over HTTP
 * works without touching the image.
 */
final class ServerConfig
{
    /**
     * @param array<int, string>    $args
     * @param array<string, string> $env
     * @param array<string, string> $headers sent with every HTTP request —
     *                                       where an Authorization goes
     */
    public function __construct(
        public readonly string $name,
        public readonly string $command = '',
        public readonly array $args = [],
        public readonly array $env = [],
        public readonly bool $enabled = true,
        public readonly string $url = '',
        public readonly array $headers = [],
    ) {}

    public function isHttp(): bool
    {
        return $this->url !== '';
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(string $name, array $data): self
    {
        $command = is_string($data['command'] ?? null) ? trim($data['command']) : '';
        $url = is_string($data['url'] ?? null) ? trim($data['url']) : '';

        // Both is not a richer configuration, it is two configurations. Picking
        // one for the user would work right up until it picked the other one.
        if ($command !== '' && $url !== '') {
            throw new McpException(
                "MCP server \"{$name}\": both 'command' and 'url' declared; pick one."
            );
        }

        if ($command === '' && $url === '') {
            throw new McpException("MCP server \"{$name}\": 'command' or 'url' missing.");
        }

        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new McpException("MCP server \"{$name}\": 'url' has to be an http(s) address: {$url}");
        }

        return new self(
            name: $name,
            command: $command,
            args: array_values(array_map(strval(...), $data['args'] ?? [])),
            env: array_map(strval(...), $data['env'] ?? []),
            // Disabled entries stay in the file so a server can be turned off
            // without losing how it was set up.
            enabled: (bool) ($data['enabled'] ?? true),
            url: $url,
            headers: array_map(strval(...), is_array($data['headers'] ?? null) ? $data['headers'] : []),
        );
    }
}
