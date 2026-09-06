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
        if (!$this->exists()) {
            return [];
        }

        $raw = @file_get_contents($this->path());
        if ($raw === false) {
            throw new McpException('Configuration MCP illisible : ' . $this->path());
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new McpException(
                'Configuration MCP invalide (' . $this->path() . ') : ' . json_last_error_msg()
            );
        }

        $declared = $data['mcpServers'] ?? $data['servers'] ?? null;
        if (!is_array($declared)) {
            throw new McpException(
                'Configuration MCP invalide (' . $this->path() . ') : clé "mcpServers" attendue.'
            );
        }

        $servers = [];

        foreach ($declared as $name => $entry) {
            if (!is_array($entry)) {
                throw new McpException("Serveur MCP « {$name} » : entrée invalide.");
            }

            $servers[] = ServerConfig::fromArray((string) $name, $entry);
        }

        return $servers;
    }
}
