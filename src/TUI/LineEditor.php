<?php

namespace App\TUI;

/**
 * The input line.
 *
 * Backed by libreadline where the extension exists, which is what gives arrow
 * keys, word motions and history their usual meaning. A bare fgets() leaves the
 * tty driver in charge instead: backspace still erases, but an arrow key lands
 * in the buffer as a literal "\e[A" — three bytes the terminal draws as four
 * columns — so from then on every backspace erases half a character and the
 * line drifts. Worse, those bytes stay in the string and execute as cursor
 * movements the moment anything echoes them back.
 *
 * Reading goes through readline's callback interface rather than the blocking
 * readline(), so that the wait happens in stream_select() and not inside C.
 * PHP dispatches an async signal only between VM instructions: blocked in
 * readline() a Ctrl+C sits unhandled until the next Enter, which makes it look
 * ignored. stream_select() returns on EINTR and the handler runs at once.
 */
class LineEditor
{
    /**
     * libreadline counts prompt width to know where to redraw. Colour codes
     * have to be bracketed as invisible or it lands in the wrong column after
     * every backspace — the very drift this class exists to remove.
     */
    private const INVIS_START = "\x01";
    private const INVIS_END   = "\x02";

    private const MAX_HISTORY = 500;

    private bool $historyLoaded = false;

    /**
     * @return string|null null on EOF (Ctrl+D), never a control sequence
     */
    public function prompt(string $label, string $colour = ''): ?string
    {
        if (!function_exists('readline') || !$this->isInteractive()) {
            echo $colour . $label . ($colour !== '' ? Terminal::RESET : '');
            $line = fgets(STDIN);

            return $line === false ? null : $this->clean($line);
        }

        $this->loadHistory();

        $line = $this->readLine($this->decorate($label, $colour));
        if ($line === null) {
            return null;
        }

        $line = $this->clean($line);
        if ($line !== '' && function_exists('readline_add_history')) {
            readline_add_history($line);
        }

        return $line;
    }

    /**
     * Read a line without recording it in history — names, paths, answers to
     * one-off questions, none of which belong in the recall of past prompts.
     */
    public function ask(string $label, string $colour = ''): string
    {
        if (!function_exists('readline') || !$this->isInteractive()) {
            echo $colour . $label . ($colour !== '' ? Terminal::RESET : '');

            return $this->clean(fgets(STDIN) ?: '');
        }

        return $this->clean((string) $this->readLine($this->decorate($label, $colour)));
    }

    /**
     * One line, read a character at a time, waiting in stream_select().
     *
     * @return string|null null on EOF (Ctrl+D)
     */
    private function readLine(string $prompt): ?string
    {
        if (!function_exists('readline_callback_handler_install')) {
            $line = readline($prompt);

            return $line === false ? null : $line;
        }

        $line = null;
        $done = false;

        // Removed from inside the callback, before returning to readline: left
        // installed, readline redraws the prompt for the next line the moment
        // this one is submitted, and that ghost prompt stays on screen above
        // whatever the answer prints.
        readline_callback_handler_install($prompt, function (?string $input) use (&$line, &$done) {
            readline_callback_handler_remove();
            $line = $input;
            $done = true;
        });

        try {
            while (!$done) {
                $read = [STDIN];
                $write = null;
                $except = null;

                // A signal makes this return false; that is not an error, it is
                // the handler having just run. Anything still pending — a quit —
                // has already happened by the time we are back here.
                if (@stream_select($read, $write, $except, null) === false) {
                    continue;
                }

                readline_callback_read_char();
            }
        } finally {
            // No-op when the callback already removed it; this covers the paths
            // that leave the loop without one, such as EOF.
            @readline_callback_handler_remove();
        }

        return $line;
    }

    public function saveHistory(): void
    {
        $file = $this->historyFile();
        if ($file === null || !function_exists('readline_write_history')) {
            return;
        }

        if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0775, true)) {
            return;
        }

        @readline_write_history($file);
        $this->truncateHistory($file);
    }

    private function decorate(string $label, string $colour): string
    {
        if ($colour === '') {
            return $label;
        }

        return self::INVIS_START . $colour . self::INVIS_END
            . $label
            . self::INVIS_START . Terminal::RESET . self::INVIS_END;
    }

    private function clean(string $line): string
    {
        return trim(Terminal::plain($line, singleLine: true));
    }

    /**
     * Whether there is anyone at the other end to answer.
     *
     * Public because the first-run model question must not be asked into a
     * pipe: a scripted run has to fall through to the configured defaults
     * rather than block forever on a menu nobody can see.
     */
    public function isInteractive(): bool
    {
        return !function_exists('posix_isatty') || posix_isatty(STDIN);
    }

    private function loadHistory(): void
    {
        if ($this->historyLoaded) {
            return;
        }
        $this->historyLoaded = true;

        $file = $this->historyFile();
        if ($file !== null && is_file($file) && function_exists('readline_read_history')) {
            @readline_read_history($file);
        }
    }

    private function historyFile(): ?string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        if (!is_string($home) || $home === '') {
            return null;
        }

        return $home . '/.config/sherpa/history';
    }

    /** PHP exposes no binding for history_truncate_file(), so trim by hand. */
    private function truncateHistory(string $file): void
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false || count($lines) <= self::MAX_HISTORY) {
            return;
        }

        @file_put_contents($file, implode("\n", array_slice($lines, -self::MAX_HISTORY)) . "\n");
    }
}
