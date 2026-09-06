<?php

declare(strict_types=1);

namespace App\TUI;

use Symfony\Component\Process\Process;

/**
 * Text onto the system clipboard, by whatever this machine has: the desktop's
 * own tool first, and else OSC 52 — an escape sequence asking the terminal to
 * do it, which also crosses SSH and a Docker container, where no clipboard
 * tool can reach the desktop.
 */
final class Clipboard
{
    /** OSC 52 payloads past this are cut off by common terminals. */
    private const OSC52_LIMIT = 100_000;

    /**
     * @param (callable(string): ?string)|null $which finds a command on PATH; injectable for tests
     */
    public function __construct(private readonly mixed $which = null) {}

    /**
     * @return string how it was copied, to say so; null when it could not be
     */
    public function copy(string $text): ?string
    {
        foreach ($this->tools() as $name => $command) {
            $path = $this->find($command[0]);
            if ($path === null) {
                continue;
            }

            // By the path found, not the bare name: what was looked up is what runs.
            $command[0] = $path;
            $process = new Process($command, timeout: 5);
            $process->setInput($text);
            $process->run();

            if ($process->isSuccessful()) {
                return $name;
            }
        }

        if (strlen($text) > self::OSC52_LIMIT || !function_exists('posix_isatty') || !posix_isatty(STDOUT)) {
            return null;
        }

        echo "\e]52;c;" . base64_encode($text) . "\x07";

        return 'the terminal (OSC 52)';
    }

    /** @return array<string, list<string>> */
    private function tools(): array
    {
        $tools = ['pbcopy' => ['pbcopy']];

        if ((getenv('WAYLAND_DISPLAY') ?: '') !== '') {
            $tools['wl-copy'] = ['wl-copy'];
        }
        if ((getenv('DISPLAY') ?: '') !== '') {
            $tools['xclip'] = ['xclip', '-selection', 'clipboard'];
            $tools['xsel'] = ['xsel', '--clipboard', '--input'];
        }

        return $tools;
    }

    private function find(string $command): ?string
    {
        if ($this->which !== null) {
            return ($this->which)($command);
        }

        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable("{$dir}/{$command}")) {
                return "{$dir}/{$command}";
            }
        }

        return null;
    }
}
