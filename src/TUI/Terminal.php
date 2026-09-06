<?php

namespace App\TUI;

class Terminal
{
    // ANSI colors
    const RESET     = "\e[0m";
    const BOLD      = "\e[1m";
    const DIM       = "\e[2m";
    const ITALIC    = "\e[3m";
    const UNDERLINE = "\e[4m";

    const BLACK   = "\e[30m";
    const RED     = "\e[31m";
    const GREEN   = "\e[32m";
    const YELLOW  = "\e[33m";
    const BLUE    = "\e[34m";
    const MAGENTA = "\e[35m";
    const CYAN    = "\e[36m";
    const WHITE   = "\e[37m";
    const GRAY    = "\e[90m";

    const BG_BLACK  = "\e[40m";
    const BG_RED    = "\e[41m";
    const BG_GREEN  = "\e[42m";
    const BG_YELLOW = "\e[43m";
    const BG_BLUE   = "\e[44m";
    const BG_GRAY   = "\e[100m";
    const BG_DARK   = "\e[48;5;235m";

    // Box-drawing
    const H  = '─';
    const V  = '│';
    const TL = '┌';
    const TR = '┐';
    const BL = '└';
    const BR = '┘';
    const TT = '┬';
    const BT = '┴';

    private string $savedStty = '';

    /**
     * Strip everything that could move the cursor, repaint the screen or leave a
     * colour latched on. Text arriving from outside — what the user typed, what a
     * tool read off disk, what the model generated — is never trusted here: a
     * stray "\e[A" anywhere in it silently overwrites the line above, which is
     * how a reply ends up printed on top of the prompt.
     *
     * Operating on bytes is safe for UTF-8: every byte removed is below 0x80,
     * and UTF-8 continuation bytes are all above it, so no character is split.
     */
    public static function plain(string $text, bool $singleLine = false): string
    {
        // CSI: \e[ params intermediates final. Cursor movement and colour.
        $text = preg_replace('/\e\[[\x30-\x3F]*[\x20-\x2F]*[\x40-\x7E]/', '', $text);
        // String introducers — OSC, DCS, SOS, PM, APC — carry a payload that runs
        // to ST or BEL. Dropping only the two-byte introducer would spill the
        // payload onto the screen as text. Window-title rewrites live here.
        $text = preg_replace('/\e[\]P\^X_][^\e\x07]*(?:\x07|\e\\\\)?/', '', $text);
        // nF sequences: \e, one or more intermediates, a final. \e(B is charset
        // selection and is three bytes, not two.
        $text = preg_replace('/\e[\x20-\x2F]+[\x30-\x7E]/', '', $text);
        // Everything else escape-led, taken whole the way a terminal would take
        // it: \ec is a full reset, \e7 saves the cursor, \eM scrolls.
        $text = preg_replace('/\e[\x20-\x7E]/', '', $text);

        // C0 controls. Tab and newline survive. Carriage return does not:
        // "\rroot@host:~# " is how output impersonates a shell prompt. A lone
        // \e (0x1B) that survived the passes above is swept up here too.
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text);

        return $singleLine ? str_replace("\n", ' ', $text) : $text;
    }

    public function rawMode(): void
    {
        $this->savedStty = shell_exec('stty -g 2>/dev/null') ?? '';
        // exec(), not system(): system() echoes the command's own stdout into
        // the middle of whatever is being drawn.
        exec('stty -echo -icanon min 1 time 0 2>/dev/null');
    }

    public function restoreMode(): void
    {
        if ($this->savedStty !== '') {
            exec('stty ' . escapeshellarg(trim($this->savedStty)) . ' 2>/dev/null');
        } else {
            exec('stty sane 2>/dev/null');
        }
    }

    /**
     * Unconditionally return the tty to a usable state, ignoring any saved
     * settings — which may themselves have been captured while already in raw
     * mode. This is the last-resort path: shutdown handler, signal handler,
     * anywhere the alternative is handing the user back a dead shell.
     */
    public function restoreSane(): void
    {
        $this->savedStty = '';
        exec('stty sane 2>/dev/null');

        // Only paint if someone is watching — this also runs from the shutdown
        // handler, and `bin/console sherpa > log` should not collect escapes.
        if ($this->isTerminal()) {
            echo self::RESET . "\e[?25h";
        }
    }

    public function clear(): void
    {
        echo "\e[2J\e[H";
    }

    public function clearLine(): void
    {
        echo "\e[2K\r";
    }

    public function hideCursor(): void
    {
        echo "\e[?25l";
    }

    public function showCursor(): void
    {
        echo "\e[?25h";
    }

    public function moveTo(int $row, int $col): void
    {
        echo "\e[{$row};{$col}H";
    }

    public function moveToCol(int $col): void
    {
        echo "\e[{$col}G";
    }

    public function saveCursor(): void
    {
        echo "\e[s";
    }

    public function restoreCursor(): void
    {
        echo "\e[u";
    }

    /**
     * @return array{0: int, 1: int} [columns, rows]
     */
    public function size(): array
    {
        // `stty size` asks the kernel via ioctl and needs no terminfo. `tput`
        // needs $TERM, which docker compose only sets when it allocates a tty —
        // relying on it alone silently pins every box to 80x24.
        $out = trim(shell_exec('stty size 2>/dev/null') ?: '');
        if (preg_match('/^(\d+)\s+(\d+)$/', $out, $m)) {
            return [(int) $m[2] ?: 80, (int) $m[1] ?: 24];
        }

        $cols = (int) (shell_exec('tput cols 2>/dev/null') ?: 0);
        $rows = (int) (shell_exec('tput lines 2>/dev/null') ?: 0);

        return [
            $cols ?: (int) (getenv('COLUMNS') ?: 80),
            $rows ?: (int) (getenv('LINES') ?: 24),
        ];
    }

    /**
     * Read one keypress, escape sequences included.
     *
     * Sequences vary in length — \e[A, \eOA, \e[1~ — and a bare Escape has
     * nothing after it at all. Reading a fixed two bytes blocks forever on the
     * first case and leaves a stray "~" in the buffer on the third, which then
     * reads back as a phantom keypress.
     */
    public function readKey(): string
    {
        $key = fread(STDIN, 1);
        if ($key === false || $key === '') {
            return '';
        }
        if ($key !== "\e") {
            return $key;
        }

        $seq = '';
        stream_set_blocking(STDIN, false);
        $deadline = microtime(true) + 0.05;

        while (microtime(true) < $deadline) {
            $c = fread(STDIN, 1);
            if ($c === false || $c === '') {
                usleep(1000);
                continue;
            }

            $seq .= $c;

            // \e followed by anything other than an introducer is a complete
            // two-byte escape; otherwise run to the sequence's final byte.
            if (strlen($seq) === 1) {
                if ($c !== '[' && $c !== 'O') {
                    break;
                }
            } elseif ($c === '~' || ctype_alpha($c)) {
                break;
            }
        }

        stream_set_blocking(STDIN, true);

        return "\e" . $seq;
    }

    public function readLine(string $prompt = ''): string
    {
        $this->restoreMode();
        echo $prompt;
        $line = trim(fgets(STDIN) ?: '');
        $this->rawMode();
        return $line;
    }

    /**
     * Draw a box with optional title.
     */
    public function box(int $x, int $y, int $width, int $height, string $title = '', string $color = ''): void
    {
        $inner = $width - 2;

        // Top border
        $this->moveTo($y, $x);
        echo $color . self::TL;
        if ($title !== '') {
            $titleStr = " {$title} ";
            $left = (int) (($inner - mb_strlen($titleStr)) / 2);
            $right = $inner - $left - mb_strlen($titleStr);
            echo str_repeat(self::H, $left) . self::BOLD . $titleStr . self::RESET . $color;
            echo str_repeat(self::H, $right);
        } else {
            echo str_repeat(self::H, $inner);
        }
        echo self::TR . self::RESET;

        // Sides, blanking the interior as we go. Drawing only the two verticals
        // leaves whatever was on screen showing through the middle of the box —
        // the confirmation overlay pops up over a live chat, and without this
        // the diff is interleaved with the conversation underneath it.
        for ($i = 1; $i < $height - 1; $i++) {
            $this->moveTo($y + $i, $x);
            echo $color . self::V . self::RESET . str_repeat(' ', max(0, $inner))
                . $color . self::V . self::RESET;
        }

        // Bottom border
        $this->moveTo($y + $height - 1, $x);
        echo $color . self::BL . str_repeat(self::H, $inner) . self::BR . self::RESET;
    }

    public function write(int $x, int $y, string $text): void
    {
        $this->moveTo($y, $x);
        echo $text;
    }

    public function writeLine(string $text, string $color = ''): void
    {
        echo $color . $text . ($color ? self::RESET : '') . "\n";
    }

    public function isTerminal(): bool
    {
        return function_exists('posix_isatty') && posix_isatty(STDOUT);
    }

    public function scrollUp(): void
    {
        echo "\e[S";
    }
}
