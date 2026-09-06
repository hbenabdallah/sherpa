<?php

declare(strict_types=1);

namespace App\TUI;

/**
 * The session so far, full screen, scrolled with Page Up and Page Down.
 *
 * Opened by Page Up at the prompt, on the alternate screen: leaving it gives
 * back the screen exactly as it was, prompt and half-typed line included,
 * since nothing was drawn over it. q, Escape, Enter — or Page Down past the
 * end — go back.
 */
final class ScrollView
{
    public function __construct(private readonly Terminal $terminal) {}

    /** @param list<string> $lines */
    public function show(array $lines): void
    {
        [$cols, $height] = $this->terminal->size();
        $page = max(1, $height - 1);

        $rows = [];
        foreach ($lines as $line) {
            array_push($rows, ...Scrollback::wrap($line, $cols));
        }

        $last = max(0, count($rows) - $page);
        // One page back: Page Up was the key that opened this.
        $top = max(0, $last - $page + 1);

        $this->terminal->enterAltScreen();
        $this->terminal->hideCursor();

        try {
            while (true) {
                $this->render($rows, $top, $page, $cols, $last);

                $key = $this->terminal->readKey();
                $top = match ($key) {
                    "\e[5~"                   => max(0, $top - $page + 1),
                    "\e[6~"                   => $top >= $last ? -1 : min($last, $top + $page - 1),
                    "\e[A", "\eOA", 'k'       => max(0, $top - 1),
                    "\e[B", "\eOB", 'j'       => min($last, $top + 1),
                    "\e[H", "\eOH", "\e[1~", 'g' => 0,
                    "\e[F", "\eOF", "\e[4~", 'G' => $last,
                    'q', 'Q', "\e", "\r", "\n", "\x03", '' => -1,
                    default                   => $top,
                };

                if ($top < 0) {
                    return;
                }
            }
        } finally {
            $this->terminal->showCursor();
            $this->terminal->leaveAltScreen();
        }
    }

    /** @param list<string> $rows */
    private function render(array $rows, int $top, int $page, int $cols, int $last): void
    {
        $out = "\e[H";
        for ($i = 0; $i < $page; $i++) {
            $out .= "\e[2K" . ($rows[$top + $i] ?? '') . Terminal::RESET . "\r\n";
        }

        $where = $rows === [] ? 'empty' : sprintf('%d–%d of %d', $top + 1, min(count($rows), $top + $page), count($rows));
        $hint = " PgUp/PgDn ↑↓ Home/End scroll · q, Esc or Enter back to the prompt · {$where} ";
        $out .= "\e[2K\e[7m" . mb_strimwidth($hint, 0, $cols) . Terminal::RESET;

        echo $out;
    }
}
