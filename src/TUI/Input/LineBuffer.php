<?php

declare(strict_types=1);

namespace App\TUI\Input;

/**
 * One line being typed: its characters, where the cursor is, and the history
 * the arrows walk through. No terminal in here — keys go in, a state comes
 * out — which is what makes the editing testable at all.
 *
 * The keys are the ones a shell has taught everybody: arrows, Home/End,
 * Delete, and the Emacs controls readline answers to (Ctrl+A, E, K, U, W).
 * Ctrl+C is not here: the terminal turns it into a signal, and the signal
 * handler decides what it means.
 *
 * A message can span lines. Enter sends it; a new line comes from Alt+Enter,
 * from Shift+ or Ctrl+Enter where the terminal tells them from Enter, or from a
 * "\" typed just before Enter — Ctrl+Enter alone is plain Enter in most
 * terminals. A paste arrives whole, its line breaks kept, between the markers
 * of bracketed paste.
 */
final class LineBuffer
{
    public const SUBMIT = 'submit';
    public const EOF = 'eof';

    /** @var list<string> one entry per character, so the cursor never splits one */
    private array $chars = [];
    private int $cursor = 0;

    /** Position in history while walking it; null when editing the draft. */
    private ?int $browsing = null;

    /** What was being typed before the arrows went into history. */
    private string $draft = '';

    /** Inside a bracketed paste: what has arrived of it so far. */
    private ?string $pasting = null;

    /**
     * Enter with a modifier, as terminals that tell it apart send it: Alt
     * (ESC then CR), and Shift/Ctrl/Alt in the xterm "modifyOtherKeys" and
     * kitty "CSI u" encodings.
     */
    private const NEWLINE_KEYS = [
        "\e\r", "\e\n",
        "\e[13;2u", "\e[13;3u", "\e[13;5u", "\e[13;6u",
        "\e[27;2;13~", "\e[27;3;13~", "\e[27;5;13~", "\e[27;6;13~",
    ];

    public const PASTE_START = "\e[200~";
    public const PASTE_END = "\e[201~";

    /** @param list<string> $history oldest first */
    public function __construct(private readonly array $history = []) {}

    /**
     * @return self::SUBMIT|self::EOF|null null while the line is still being typed
     */
    public function feed(string $key): ?string
    {
        // A paste is text, all of it: its line breaks are not Enter.
        if ($this->pasting !== null) {
            if ($key === self::PASTE_END) {
                $this->paste($this->pasting);
                $this->pasting = null;
            } elseif ($key === '' || $key[0] !== "\e") {
                $this->pasting .= $key;
            }

            return null;
        }
        if ($key === self::PASTE_START) {
            $this->pasting = '';

            return null;
        }

        if (in_array($key, self::NEWLINE_KEYS, true)) {
            $this->insert("\n");

            return null;
        }

        switch ($key) {
            case "\r":
            case "\n":
                // "\" then Enter: the line goes on, as in a shell.
                if ($this->cursor > 0 && $this->chars[$this->cursor - 1] === '\\') {
                    $this->chars[$this->cursor - 1] = "\n";

                    return null;
                }
                return self::SUBMIT;

            case "\x04": // Ctrl+D: the end of input on an empty line, Delete otherwise
                if ($this->chars === []) {
                    return self::EOF;
                }
                $this->delete();
                return null;

            case "\x7f":
            case "\x08":
                $this->backspace();
                return null;

            case "\e[3~":
                $this->delete();
                return null;

            case "\e[D":
            case "\eOD":
            case "\x02":
                $this->cursor = max(0, $this->cursor - 1);
                return null;

            case "\e[C":
            case "\eOC":
            case "\x06":
                $this->cursor = min(count($this->chars), $this->cursor + 1);
                return null;

            case "\e[H":
            case "\eOH":
            case "\e[1~":
            case "\x01":
                $this->cursor = $this->lineStart();
                return null;

            case "\e[F":
            case "\eOF":
            case "\e[4~":
            case "\x05":
                $this->cursor = $this->lineEnd();
                return null;

            case "\x0b": // Ctrl+K: to the end of the line
                array_splice($this->chars, $this->cursor, $this->lineEnd() - $this->cursor);
                return null;

            case "\x15": // Ctrl+U: to the start of the line
                $start = $this->lineStart();
                array_splice($this->chars, $start, $this->cursor - $start);
                $this->cursor = $start;
                return null;

            case "\x17": // Ctrl+W: the word before the cursor
                $this->deleteWordBefore();
                return null;

            // Between the lines of a message; past its first or last line,
            // through history, as on a single line.
            case "\e[A":
            case "\eOA":
                $this->lineStart() > 0 ? $this->verticalMove(-1) : $this->older();
                return null;

            case "\e[B":
            case "\eOB":
                $this->lineEnd() < count($this->chars) ? $this->verticalMove(1) : $this->newer();
                return null;

            case "\t":
                $this->insert('    ');
                return null;
        }

        // Any other escape sequence or control character is a key this editor
        // does not bind. Inserted, it would print as cursor movement the moment
        // the line is echoed back — the very corruption this class replaces.
        if ($key === '' || $key[0] === "\e" || ord($key[0]) < 0x20) {
            return null;
        }

        $this->insert($key);

        return null;
    }

    /** Replace the whole line — a completion taken from the menu — the cursor at its end. */
    public function set(string $text): void
    {
        $this->browsing = null;
        $this->replace($text);
    }

    public function text(): string
    {
        return implode('', $this->chars);
    }

    /** @return list<string> */
    public function chars(): array
    {
        return $this->chars;
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    /**
     * Text arriving whole: its line breaks kept as line breaks, whatever the
     * system that wrote them, and the other control characters dropped.
     */
    public function paste(string $text): void
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\t", '    ', $text);
        $text = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]|\e\[[0-9;?]*[ -\/]*[@-~]/', '', $text);

        $this->browsing = null;
        $this->insert($text);
    }

    /** Where the cursor's line starts. */
    public function lineStart(): int
    {
        $i = $this->cursor;
        while ($i > 0 && $this->chars[$i - 1] !== "\n") {
            $i--;
        }

        return $i;
    }

    /** Where the cursor's line ends, before its line break. */
    public function lineEnd(): int
    {
        $i = $this->cursor;
        $n = count($this->chars);
        while ($i < $n && $this->chars[$i] !== "\n") {
            $i++;
        }

        return $i;
    }

    /** One line up or down, keeping the column where the line is long enough. */
    private function verticalMove(int $direction): void
    {
        $column = $this->cursor - $this->lineStart();

        if ($direction < 0) {
            $this->cursor = $this->lineStart() - 1;
            $start = $this->lineStart();
        } else {
            $this->cursor = $this->lineEnd() + 1;
            $start = $this->cursor;
        }

        $this->cursor = min($start + $column, $this->lineEnd());
    }

    private function insert(string $text): void
    {
        $new = mb_str_split($text);
        array_splice($this->chars, $this->cursor, 0, $new);
        $this->cursor += count($new);
    }

    private function backspace(): void
    {
        if ($this->cursor === 0) {
            return;
        }

        array_splice($this->chars, $this->cursor - 1, 1);
        $this->cursor--;
    }

    private function delete(): void
    {
        if ($this->cursor < count($this->chars)) {
            array_splice($this->chars, $this->cursor, 1);
        }
    }

    private function deleteWordBefore(): void
    {
        $start = $this->cursor;

        while ($start > 0 && $this->chars[$start - 1] === ' ') {
            $start--;
        }
        while ($start > 0 && $this->chars[$start - 1] !== ' ') {
            $start--;
        }

        array_splice($this->chars, $start, $this->cursor - $start);
        $this->cursor = $start;
    }

    private function older(): void
    {
        if ($this->history === []) {
            return;
        }

        if ($this->browsing === null) {
            $this->draft = $this->text();
            $this->browsing = count($this->history);
        }

        if ($this->browsing > 0) {
            $this->browsing--;
            $this->replace($this->history[$this->browsing]);
        }
    }

    private function newer(): void
    {
        if ($this->browsing === null) {
            return;
        }

        $this->browsing++;

        if ($this->browsing >= count($this->history)) {
            // Past the newest entry is what was being typed before — not lost
            // because someone pressed Up to look at something.
            $this->browsing = null;
            $this->replace($this->draft);

            return;
        }

        $this->replace($this->history[$this->browsing]);
    }

    private function replace(string $text): void
    {
        $this->chars = $text === '' ? [] : mb_str_split($text);
        $this->cursor = count($this->chars);
    }
}
