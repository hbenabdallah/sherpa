<?php

namespace App\Mcp;

/**
 * Where MCP servers are declared: ~/.config/sherpa/mcp.json.
 *
 * Same file shape as other MCP clients use, so a working configuration can be
 * copied over rather than translated.
 */
class McpConfig
{
    private ?string $overridePath = null;

    /** Test seam, and a way to keep a project's servers with the project. */
    public function setPath(?string $path): void
    {
        $this->overridePath = $path;
    }

    public function path(): string
    {
        return $this->overridePath
            ?? (($_SERVER['HOME'] ?? getenv('HOME')) . '/.config/sherpa/mcp.json');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * @return array<int, ServerConfig>
     *
     * @throws McpException when the file exists but cannot be understood —
     *                      silently ignoring a typo would leave someone
     *                      wondering where their servers went
     */
    public function servers(): array
    {
        [$data, $key] = $this->document();

        $servers = [];

        foreach ($data[$key] as $name => $entry) {
            if (!is_array($entry)) {
                throw new McpException("MCP server “{$name}”: entry must be an object.");
            }

            $servers[] = ServerConfig::fromArray((string) $name, $entry);
        }

        return $servers;
    }

    /** The server of that name as declared, or null. @throws McpException */
    public function server(string $name): ?ServerConfig
    {
        foreach ($this->servers() as $server) {
            if ($server->name === $name) {
                return $server;
            }
        }

        return null;
    }

    /**
     * Declare a server, or replace the one of that name. The rest of the file
     * — other servers, comments, keys Sherpa does not know — is kept as it is.
     *
     * @throws McpException when the name is not one a tool prefix can carry,
     *                      or the file cannot be read or written
     */
    public function save(ServerConfig $server): void
    {
        if (!ServerConfig::validName($server->name)) {
            throw new McpException("“{$server->name}” cannot name an MCP server: letters, digits, - and _ only.");
        }

        [$data, $key] = $this->document();
        $data[$key][$server->name] = $server->toArray();

        $this->write($data);
    }

    /** False when no server has that name. @throws McpException */
    public function remove(string $name): bool
    {
        [$data, $key] = $this->document();
        if (!isset($data[$key][$name])) {
            return false;
        }

        unset($data[$key][$name]);
        $this->write($data);

        return true;
    }

    /**
     * Turn a server on or off, keeping how it was set up. False when no
     * server has that name.
     *
     * @throws McpException
     */
    public function setEnabled(string $name, bool $enabled): bool
    {
        [$data, $key] = $this->document();
        if (!is_array($data[$key][$name] ?? null)) {
            return false;
        }

        if ($enabled) {
            unset($data[$key][$name]['enabled']);
        } else {
            $data[$key][$name]['enabled'] = false;
        }
        $this->write($data);

        return true;
    }

    /**
     * The whole file, and the key its servers are under: "mcpServers", or
     * "servers" in a file written for a client that uses that one.
     *
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws McpException
     */
    private function document(): array
    {
        if (!$this->exists()) {
            return [['mcpServers' => []], 'mcpServers'];
        }

        $raw = @file_get_contents($this->path());
        if ($raw === false) {
            throw new McpException('Cannot read the MCP configuration: ' . $this->path());
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new McpException(
                'Invalid MCP configuration (' . $this->path() . '): ' . json_last_error_msg()
            );
        }

        $key = isset($data['mcpServers']) || !isset($data['servers']) ? 'mcpServers' : 'servers';
        if (!is_array($data[$key] ?? null)) {
            throw new McpException(
                'Invalid MCP configuration (' . $this->path() . '): a "mcpServers" key was expected.'
            );
        }

        return [$data, $key];
    }

    /**
     * Written whole, through a file beside it renamed into place: a crash
     * halfway leaves the old file, not half of a new one. Readable by its
     * owner only, since headers and env hold tokens.
     *
     * @param array<string, mixed> $data
     *
     * @throws McpException
     */
    private function write(array $data): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new McpException("Cannot create {$dir}");
        }

        // An empty list is written as {}, which is what the file means.
        foreach (['mcpServers', 'servers'] as $key) {
            if (($data[$key] ?? null) === []) {
                $data[$key] = new \stdClass();
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, $json) === false || !@chmod($tmp, 0600) || !@rename($tmp, $path)) {
            @unlink($tmp);

            throw new McpException("Cannot write {$path}");
        }
    }
}
