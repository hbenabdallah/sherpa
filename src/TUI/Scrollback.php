<?php

declare(strict_types=1);

namespace App\TUI;

/**
 * Everything the session printed, kept as lines, so it can be scrolled
 * through again with Page Up — the terminal's own scrollback is out of reach
 * from here, and Page Up without Shift reaches the program, not the terminal.
 *
 * Captured on the way out, through an output buffer that passes everything on
 * unchanged. What it keeps is what a terminal would show: colours stay, cursor
 * movement goes, and a carriage return starts the line over — which is how the
 * waiting line, redrawn with \r, leaves nothing behind but what replaced it.
 */
final class Scrollback
{
    private const MAX_LINES = 5000;

    /** @var list<string> */
    private array $lines = [];
    private string $current = '';
    private bool $capturing = false;
    private bool $paused = false;

    /** Start capturing whatever is printed from now on. Once. */
    public function start(): void
    {
        if ($this->capturing) {
            return;
        }
        $this->capturing = true;

        // A chunk size of 1 hands each write on at once: nothing waits in the
        // buffer, so the screen never lags behind what was printed.
        ob_start(function (string $buffer): string {
            if (!$this->paused) {
                $this->feed($buffer);
            }

            return $buffer;
        }, 1);
    }

    /**
     * Stop keeping what is printed, and start again. The input line is
     * redrawn at every keypress: its final state is recorded instead.
     */
    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    /** Text as if it had been printed. */
    public function feed(string $text): void
    {
        $parts = preg_split('/(\e\[[0-9;?<=>]*[ -\/]*[@-~]|\e[^\[]|\r|\n)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($parts as $part) {
            if ($part === "\n") {
                $this->lines[] = $this->current;
                $this->current = '';
            } elseif ($part === "\r") {
                $this->current = '';
            } elseif ($part[0] === "\e") {
                // Colours kept; every other sequence moves or erases, which a
                // list of lines has no use for.
                if (preg_match('/^\e\[[0-9;]*m$/', $part) === 1) {
                    $this->current .= $part;
                }
            } else {
                $this->current .= $part;
            }
        }

        if (count($this->lines) > self::MAX_LINES) {
            $this->lines = array_slice($this->lines, -self::MAX_LINES);
        }
    }

    /** @return list<string> every line, the one being written included */
    public function lines(): array
    {
        return $this->current === '' ? $this->lines : [...$this->lines, $this->current];
    }

    /**
     * $line cut into rows of at most $width columns, its colours carried from
     * one row to the next.
     *
     * @return list<string>
     */
    public static function wrap(string $line, int $width): array
    {
        $width = max(1, $width);
        $rows = [];
        $row = '';
        $used = 0;
        $active = '';

        $parts = preg_split('/(\e\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            if ($part[0] === "\e") {
                $row .= $part;
                $active = preg_match('/^\e\[0?m$/', $part) === 1 ? '' : $active . $part;
                continue;
            }

            foreach (mb_str_split(str_replace("\t", '    ', $part)) as $char) {
                $w = mb_strwidth($char);
                if ($used + $w > $width) {
                    $rows[] = $row . ($active !== '' ? Terminal::RESET : '');
                    $row = $active;
                    $used = 0;
                }
                $row .= $char;
                $used += $w;
            }
        }

        $rows[] = $row;

        return $rows;
    }
}
