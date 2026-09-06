<?php

namespace App\Mcp;

/**
 * JSON-RPC over a child process's stdin and stdout, one message per line.
 *
 * The server is somebody else's program. It can exit at the handshake, print a
 * stack trace where a response belongs, or answer nothing at all — so every
 * read has a deadline and stderr is drained rather than left to fill its pipe
 * buffer and deadlock the very process we are waiting on.
 */
final class StdioTransport
{
    /** A line longer than this is a server misbehaving, not a message. */
    private const MAX_LINE_BYTES = 4 * 1024 * 1024;

    /** How much stderr to keep for the error message when something fails. */
    private const STDERR_KEEP = 4000;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $buffer = '';
    private string $stderr = '';

    public function __construct(private readonly ServerConfig $config) {}

    public function start(): void
    {
        if ($this->process !== null) {
            return;
        }

        $command = array_merge([$this->config->command], $this->config->args);

        // Fail here rather than on a silent empty read later: proc_open reports
        // a missing binary only through the child's exit status.
        if ($this->which($this->config->command) === null) {
            throw new McpException(
                "Serveur MCP « {$this->config->name} » : commande introuvable : {$this->config->command}"
            );
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(
            $command,
            $descriptors,
            $pipes,
            null,
            $this->config->env === [] ? null : array_merge(getenv(), $this->config->env),
        );

        if (!is_resource($process)) {
            throw new McpException("Serveur MCP « {$this->config->name} » : impossible de lancer {$this->config->command}.");
        }

        $this->process = $process;
        $this->pipes = $pipes;

        // Reads have their own deadlines; blocking pipes would ignore them.
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    /** @param array<string, mixed> $message */
    public function send(array $message): void
    {
        if ($this->process === null) {
            throw new McpException("Serveur MCP « {$this->config->name} » : non démarré.");
        }

        $line = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        if (@fwrite($this->pipes[0], $line) === false) {
            throw new McpException($this->describeDeath('écriture impossible'));
        }
    }

    /**
     * Read one message, waiting at most $timeout seconds for it.
     *
     * @return array<string, mixed>|null null when the deadline passed with no
     *                                   complete line available
     */
    public function receive(float $timeout): ?array
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            $line = $this->takeLine();
            if ($line !== null) {
                $decoded = json_decode($line, true);

                // Servers print to stdout when they should not — banners,
                // warnings, a stray print in a dependency. Skip what is not a
                // message rather than treating it as a protocol failure.
                if (is_array($decoded)) {
                    return $decoded;
                }

                continue;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }

            $read = [$this->pipes[1], $this->pipes[2]];
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 0, (int) min(200000, $remaining * 1E6)) === false) {
                continue; // interrupted by a signal
            }

            $this->drainStderr();

            $chunk = @fread($this->pipes[1], 65536);

            if ($chunk === false || ($chunk === '' && feof($this->pipes[1]))) {
                throw new McpException($this->describeDeath('flux fermé'));
            }

            $this->buffer .= $chunk;

            if (strlen($this->buffer) > self::MAX_LINE_BYTES) {
                throw new McpException("Serveur MCP « {$this->config->name} » : réponse démesurée, abandon.");
            }
        }
    }

    public function close(): void
    {
        if ($this->process === null) {
            return;
        }

        // Closing stdin is how an MCP server is asked to stop. Give it a moment
        // to do so before insisting.
        @fclose($this->pipes[0]);

        for ($i = 0; $i < 20; $i++) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                break;
            }
            usleep(25000);
        }

        foreach ([1, 2] as $fd) {
            if (isset($this->pipes[$fd]) && is_resource($this->pipes[$fd])) {
                @fclose($this->pipes[$fd]);
            }
        }

        @proc_terminate($this->process);
        @proc_close($this->process);

        $this->process = null;
        $this->pipes = [];
    }

    public function isRunning(): bool
    {
        return $this->process !== null && proc_get_status($this->process)['running'];
    }

    private function takeLine(): ?string
    {
        $at = strpos($this->buffer, "\n");
        if ($at === false) {
            return null;
        }

        $line = substr($this->buffer, 0, $at);
        $this->buffer = substr($this->buffer, $at + 1);

        return trim($line) === '' ? $this->takeLine() : $line;
    }

    /**
     * MCP servers use stderr for logging, and a full pipe buffer blocks the
     * writer — which here is the process we are waiting on for an answer.
     */
    private function drainStderr(): void
    {
        $chunk = @fread($this->pipes[2], 65536);

        if (is_string($chunk) && $chunk !== '') {
            $this->stderr = substr($this->stderr . $chunk, -self::STDERR_KEEP);
        }
    }

    private function describeDeath(string $what): string
    {
        $this->drainStderr();

        $status = $this->process !== null ? proc_get_status($this->process) : null;
        $exit = $status !== null && !$status['running'] ? " (code {$status['exitcode']})" : '';
        $log = trim($this->stderr);

        return "Serveur MCP « {$this->config->name} » : {$what}{$exit}."
            . ($log === '' ? '' : "\n" . $log);
    }

    private function which(string $command): ?string
    {
        if (str_contains($command, '/')) {
            return is_executable($command) ? $command : null;
        }

        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable($dir . '/' . $command)) {
                return $dir . '/' . $command;
            }
        }

        return null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
