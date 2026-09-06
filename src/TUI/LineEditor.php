<?php

namespace App\TUI;

use App\TUI\Input\KeyReader;
use App\TUI\Input\LineBuffer;
use App\TUI\Input\LineView;
use App\TUI\Input\SlashMenu;

/**
 * The input line: libreadline where the extension exists, an editor written
 * here where it does not — the static PHP on the host has none. What must never
 * happen is the third option, a bare fgets(): an arrow key then lands in the
 * buffer as a literal "\e[A", every backspace erases half a character, and
 * those bytes execute as cursor movements when anything echoes them back.
 *
 * The main prompt always uses the editor written here, readline or not: it is
 * the one that can show the command menu under "/" and open the scrollback on
 * Page Up. Other questions still go through readline where it exists.
 *
 * Reading goes through readline's callback interface rather than the blocking
 * readline(), so the wait happens in stream_select(): PHP dispatches an async
 * signal only between VM instructions, so inside C a Ctrl+C would sit unhandled
 * until the next Enter.
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

    /** Past lines, oldest first, when there is no libreadline to keep them. */
    private array $history = [];

    /**
     * Keys read but not used yet: what followed the first line of a multi-line
     * paste. readline keeps them for the next prompt, and so does this.
     *
     * @var list<string>
     */
    private array $pending = [];

    /** What "/" offers at the main prompt; null until the command sets it. */
    private ?SlashMenu $menu = null;

    public function __construct(private readonly ?Scrollback $scrollback = null) {}

    /**
     * The commands and skills "/" lists.
     *
     * @param \Closure(): list<array{name: string, hint: string, description: string}> $entries
     */
    public function useCompletions(\Closure $entries): void
    {
        $this->menu = new SlashMenu($entries);
    }

    /**
     * @return string|null null on EOF (Ctrl+D), never a control sequence
     */
    public function prompt(string $label, string $colour = ''): ?string
    {
        if (!$this->isInteractive()) {
            echo $colour . $label . ($colour !== '' ? Terminal::RESET : '');
            $line = fgets(STDIN);

            return $line === false ? null : $this->clean($line);
        }

        $this->loadPlainHistory();

        $line = $this->edit($label, $colour, $this->history, main: true);
        if ($line === null) {
            return null;
        }

        $line = $this->clean($line);
        if ($line !== '' && end($this->history) !== $line) {
            $this->history[] = $line;
        }

        return $line;
    }

    /**
     * Read a line without recording it in history — names, paths, answers to
     * one-off questions, none of which belong in the recall of past prompts.
     */
    public function ask(string $label, string $colour = ''): string
    {
        if (!$this->isInteractive()) {
            echo $colour . $label . ($colour !== '' ? Terminal::RESET : '');

            return $this->clean(fgets(STDIN) ?: '');
        }

        if (!$this->hasReadline()) {
            return $this->clean($this->edit($label, $colour, []) ?? '');
        }

        return $this->clean((string) $this->readLine($this->decorate($label, $colour)));
    }

    /**
     * Read a line that must not show: an API key. Not echoed, not kept in any
     * history, and the newline printed afterwards so the next line starts
     * where it should.
     */
    public function askSecret(string $label, string $colour = ''): string
    {
        echo $colour . $label . ($colour !== '' ? Terminal::RESET : '');

        if (!$this->isInteractive()) {
            return trim($this->clean(fgets(STDIN) ?: ''));
        }

        $saved = trim((string) shell_exec('stty -g 2>/dev/null'));
        exec('stty -echo icanon 2>/dev/null');
        try {
            $line = fgets(STDIN);
        } finally {
            exec($saved !== '' ? 'stty ' . escapeshellarg($saved) . ' 2>/dev/null' : 'stty echo 2>/dev/null');
            echo "\n";
        }

        return trim($this->clean($line ?: ''));
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
        if ($file === null) {
            return;
        }

        if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0775, true)) {
            return;
        }

        if ($this->historyLoaded) {
            // One line per entry — the format GNU readline writes too, so the
            // same history reads back whichever PHP runs Sherpa next time.
            @file_put_contents($file, implode("\n", $this->history) . ($this->history === [] ? '' : "\n"));
        }

        $this->truncateHistory($file);
    }

    /**
     * readline's callback interface, specifically. The blocking readline()
     * alone would wait inside C, where a Ctrl+C sits unhandled until Enter.
     */
    private function hasReadline(): bool
    {
        return function_exists('readline_callback_handler_install');
    }

    /**
     * One line, edited without libreadline: raw mode, keys read in
     * stream_select() so Ctrl+C is handled at once, the line redrawn after each.
     * At the main prompt, also the menu under "/" and Page Up.
     *
     * @param list<string> $history
     *
     * @return string|null null on end of input (Ctrl+D on an empty line)
     */
    private function edit(string $label, string $colour, array $history, bool $main = false): ?string
    {
        $terminal = new Terminal();
        $buffer = new LineBuffer($history);
        $menu = $main ? $this->menu : null;
        $menu?->update('', true);
        $shown = $colour . $label . ($colour !== '' ? Terminal::RESET : '');
        $promptWidth = mb_strwidth(Terminal::plain($label));
        $columns = $terminal->size()[0];
        $carry = '';

        // Redrawn at every key: only the line as submitted belongs in the
        // transcript, and it is added below.
        $this->scrollback?->pause();
        $terminal->rawMode();

        try {
            while (true) {
                while ($this->pending !== []) {
                    $result = $this->handleKey(array_shift($this->pending), $buffer, $menu, $main);

                    if ($result !== null) {
                        $this->draw($shown, $promptWidth, $buffer, $columns);
                        echo "\r\n";
                        if ($result !== LineBuffer::EOF) {
                            $this->scrollback?->feed($shown . $buffer->text() . "\n");
                        }

                        return $result === LineBuffer::EOF ? null : $buffer->text();
                    }

                    $menu?->update($buffer->text(), $buffer->cursor() === count($buffer->chars()));
                }

                $this->draw($shown, $promptWidth, $buffer, $columns, $menu?->rows($columns) ?? []);

                $read = [STDIN];
                $write = null;
                $except = null;
                // With half an escape sequence in hand, wait a moment for the
                // rest; if nothing comes, it was a lone Escape.
                $ready = $carry === ''
                    ? @stream_select($read, $write, $except, null)
                    : @stream_select($read, $write, $except, 0, 50_000);

                if ($ready === false) {
                    // A signal, handled by now. Not an error.
                    continue;
                }

                if ($ready === 0) {
                    if ($carry === "\e") {
                        $this->pending[] = "\e";
                    }
                    $carry = '';
                    continue;
                }

                $bytes = fread(STDIN, 4096);
                if ($bytes === false || $bytes === '') {
                    echo "\r\n";

                    return null;
                }

                [$keys, $carry] = KeyReader::split($carry . $bytes);
                array_push($this->pending, ...$keys);
            }
        } finally {
            $terminal->restoreMode();
            $this->scrollback?->resume();
        }
    }

    /**
     * One key at the prompt. With the menu open, the arrows move in it rather
     * than through history, Tab takes the entry, Enter takes and runs it.
     *
     * @return LineBuffer::SUBMIT|LineBuffer::EOF|null
     */
    private function handleKey(string $key, LineBuffer $buffer, ?SlashMenu $menu, bool $main): ?string
    {
        $entry = $menu?->isOpen() ? $menu->current() : null;

        if ($entry !== null) {
            switch ($key) {
                case "\e[A":
                case "\eOA":
                    $menu->up();
                    return null;

                case "\e[B":
                case "\eOB":
                    $menu->down();
                    return null;

                case "\t":
                    $buffer->set(SlashMenu::completion($entry));
                    return null;

                case "\e":
                    $menu->dismiss();
                    return null;

                case "\r":
                case "\n":
                    // Typed in full already, or a command that needs nothing
                    // more: run it. One that needs arguments waits for them.
                    if (strtolower($buffer->text()) === strtolower($entry['name']) || SlashMenu::runsAlone($entry)) {
                        $buffer->set($entry['name']);

                        return LineBuffer::SUBMIT;
                    }
                    $buffer->set(SlashMenu::completion($entry));
                    return null;
            }
        }

        if ($main && $key === "\e[5~") {
            (new ScrollView(new Terminal()))->show($this->scrollback?->lines() ?? []);

            return null;
        }

        return $key === "\e" ? null : $buffer->feed($key);
    }

    /** @param list<string> $below rows drawn under the line: the menu, when it is open */
    private function draw(string $shown, int $promptWidth, LineBuffer $buffer, int $columns, array $below = []): void
    {
        [$visible, $cursorColumn] = LineView::window($buffer->chars(), $buffer->cursor(), $columns - $promptWidth - 1);
        $move = $promptWidth + $cursorColumn;

        $out = "\r" . $shown . $visible . "\e[K";
        foreach ($below as $row) {
            $out .= "\r\n" . $row . "\e[K";
        }
        // Whatever a longer menu left under this one, cleared.
        $out .= "\e[J" . ($below !== [] ? "\e[" . count($below) . 'A' : '');

        echo $out . "\r" . ($move > 0 ? "\e[{$move}C" : '');
    }

    private function loadPlainHistory(): void
    {
        if ($this->historyLoaded) {
            return;
        }
        $this->historyLoaded = true;

        $file = $this->historyFile();
        if ($file === null || !is_file($file)) {
            return;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->history = is_array($lines) ? array_slice(array_values($lines), -self::MAX_HISTORY) : [];
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
     * Whether there is anyone at the other end to answer: the first-run
     * question must not be asked into a pipe, where a scripted run would block
     * forever on a menu nobody can see.
     */
    public function isInteractive(): bool
    {
        return !function_exists('posix_isatty') || posix_isatty(STDIN);
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
