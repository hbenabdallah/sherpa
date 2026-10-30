<?php

declare(strict_types=1);

namespace App\Tool;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Where a command run on this machine may write: the project, the temporary
 * directories and the package managers' caches, nothing else. Everything stays
 * readable, so the project's own tools work as they do in a terminal; what a
 * command can no longer do is change the rest of the home — a shell profile,
 * an SSH configuration, Sherpa's own grants in projects.yaml — or read the API
 * keys Sherpa keeps.
 *
 * bubblewrap on Linux, sandbox-exec on macOS. Neither there, or turned off
 * with SHERPA_SANDBOX=off, commands run as before and Sherpa says so at start.
 * SHERPA_SANDBOX=strict also cuts the network. A command run in the project's
 * container is not wrapped: the container is its sandbox.
 */
class Sandbox
{
    public const BUBBLEWRAP = 'bubblewrap';

    public const SANDBOX_EXEC = 'sandbox-exec';

    /**
     * Under the home, where installs and test runs write what they download
     * and build: refusing them would fail `composer install` and `npm test`.
     */
    private const array CACHES = [
        '.cache', '.composer', '.config/composer', '.local/share/composer', '.npm', '.yarn',
        '.pnpm-store', '.local/share/pnpm', '.cargo/registry', '.cargo/git', 'go/pkg', '.gradle',
        '.m2', '.bundle', '.gem', '.nuget', 'Library/Caches',
    ];

    /** @var array{0: ?string, 1: ?string}|null what runs commands, or why nothing does */
    private ?array $detected = null;

    public function __construct(
        // auto, strict or off: SHERPA_SANDBOX when not given.
        private readonly ?string $mode = null,
        private readonly ?string $home = null,
        private readonly string $os = PHP_OS_FAMILY,
    ) {}

    /** What keeps commands in, or null when nothing does; see why(). */
    public function kind(): ?string
    {
        return $this->detect()[0];
    }

    /** Why commands run without a sandbox, or null when they run in one. */
    public function why(): ?string
    {
        return $this->detect()[1];
    }

    public function strict(): bool
    {
        return $this->mode() === 'strict';
    }

    /**
     * The words to put before a command so it runs in the sandbox, rooted at
     * the project: none when there is no sandbox.
     *
     * @return list<string>
     */
    public function prefix(string $root): array
    {
        $root = realpath($root) ?: $root;

        return match ($this->kind()) {
            self::BUBBLEWRAP   => $this->bubblewrap($root),
            self::SANDBOX_EXEC => ['sandbox-exec', '-p', $this->profile($root)],
            default            => [],
        };
    }

    /** One line for the confirmation and the start of a session. */
    public function describe(): string
    {
        if ($this->kind() === null) {
            return 'sandbox: none — ' . $this->why();
        }

        return "sandbox ({$this->kind()}): writes only to the project, /tmp and caches"
            . ($this->strict() ? ', no network' : '');
    }

    /**
     * The paths a command may write to, besides the project.
     *
     * @return list<string>
     */
    public function writable(): array
    {
        $paths = [];
        foreach (['/tmp', '/var/tmp', sys_get_temp_dir()] as $dir) {
            $real = realpath($dir);
            if ($real !== false) {
                $paths[] = $real;
            }
        }

        $home = $this->homeDir();
        if ($home !== null) {
            foreach (self::CACHES as $cache) {
                $real = realpath($home . '/' . $cache);
                if ($real !== false && is_dir($real)) {
                    $paths[] = $real;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /** @return list<string> */
    private function bubblewrap(string $root): array
    {
        $args = ['bwrap', '--ro-bind', '/', '/', '--dev', '/dev', '--proc', '/proc'];
        foreach ([...$this->writable(), $root] as $path) {
            $args[] = '--bind';
            $args[] = $path;
            $args[] = $path;
        }
        foreach ($this->secrets() as $secret) {
            $args[] = '--ro-bind';
            $args[] = '/dev/null';
            $args[] = $secret;
        }
        if ($this->strict()) {
            $args[] = '--unshare-net';
        }

        return [...$args, '--die-with-parent', '--'];
    }

    /** A sandbox-exec profile: everything allowed, but writing, and reading the keys. */
    private function profile(string $root): string
    {
        $quote = static fn(string $path): string => '"' . addcslashes($path, '"\\') . '"';
        $writable = array_map(
            static fn(string $path): string => '(subpath ' . $quote($path) . ')',
            [...$this->writable(), $root, '/private/var/folders'],
        );

        return "(version 1)\n(allow default)\n"
            . "(deny file-write*)\n"
            . '(allow file-write* ' . implode(' ', $writable)
            . ' (literal "/dev/null") (literal "/dev/zero") (regex #"^/dev/tty") (regex #"^/dev/fd/") (literal "/dev/stdout") (literal "/dev/stderr"))' . "\n"
            . implode('', array_map(static fn(string $secret): string => '(deny file-read* (literal ' . $quote($secret) . "))\n", $this->secrets()))
            . ($this->strict() ? "(deny network*)\n(allow network* (local unix))\n" : '');
    }

    /**
     * Files no command reads: the API keys Sherpa keeps.
     *
     * @return list<string>
     */
    private function secrets(): array
    {
        $home = $this->homeDir();
        $keys = $home === null ? false : realpath($home . '/.config/sherpa/keys.yaml');

        return $keys === false ? [] : [$keys];
    }

    /** @return array{0: ?string, 1: ?string} */
    private function detect(): array
    {
        if ($this->detected !== null) {
            return $this->detected;
        }

        if ($this->mode() === 'off') {
            return $this->detected = [null, 'turned off with SHERPA_SANDBOX=off'];
        }
        // Sherpa in its own container: commands run there, and only there.
        if ($this->os === 'Linux' && is_file('/.dockerenv')) {
            return $this->detected = [null, "Sherpa runs in Docker, whose container keeps commands in"];
        }

        [$kind, $program, $install] = match ($this->os) {
            'Linux'  => [self::BUBBLEWRAP, 'bwrap', 'install bubblewrap (bwrap) to keep commands in the project'],
            'Darwin' => [self::SANDBOX_EXEC, 'sandbox-exec', 'sandbox-exec is missing from this Mac'],
            default  => [null, null, "no sandbox is known on {$this->os}"],
        };
        if ($kind === null || !self::onPath((string) $program)) {
            return $this->detected = [null, $install];
        }

        // Installed is not working: unprivileged user namespaces can be
        // turned off, and bubblewrap then fails on every command.
        $this->detected = [$kind, null];
        $probe = new Process([...$this->prefix(sys_get_temp_dir()), 'true'], timeout: 10);

        try {
            $probe->run();
        } catch (Throwable $e) {
            return $this->detected = [null, "{$program} cannot start here: " . $e->getMessage()];
        }
        if (!$probe->isSuccessful()) {
            $error = trim(strtok(trim($probe->getErrorOutput()), "\n") ?: '');

            return $this->detected = [null, "{$program} cannot start here" . ($error === '' ? '' : ": {$error}")];
        }

        return $this->detected;
    }

    private function mode(): string
    {
        $mode = strtolower(trim($this->mode ?? (string) getenv('SHERPA_SANDBOX')));

        return in_array($mode, ['off', 'strict'], true) ? $mode : 'auto';
    }

    private function homeDir(): ?string
    {
        $home = $this->home ?? ($_SERVER['HOME'] ?? getenv('HOME'));

        return is_string($home) && $home !== '' ? $home : null;
    }

    private static function onPath(string $program): bool
    {
        return array_any(explode(PATH_SEPARATOR, (string) getenv('PATH')), fn($dir): bool => $dir !== '' && is_executable($dir . '/' . $program));
    }
}
