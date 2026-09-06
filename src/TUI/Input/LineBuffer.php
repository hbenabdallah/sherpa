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

    /** @param list<string> $history oldest first */
    public function __construct(private readonly array $history = []) {}

    /**
     * @return self::SUBMIT|self::EOF|null null while the line is still being typed
     */
    public function feed(string $key): ?string
    {
        switch ($key) {
            case "\r":
            case "\n":
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
                $this->cursor = 0;
                return null;

            case "\e[F":
            case "\eOF":
            case "\e[4~":
            case "\x05":
                $this->cursor = count($this->chars);
                return null;

            case "\x0b": // Ctrl+K: to the end of the line
                array_splice($this->chars, $this->cursor);
                return null;

            case "\x15": // Ctrl+U: to the start of the line
                array_splice($this->chars, 0, $this->cursor);
                $this->cursor = 0;
                return null;

            case "\x17": // Ctrl+W: the word before the cursor
                $this->deleteWordBefore();
                return null;

            case "\e[A":
            case "\eOA":
                $this->older();
                return null;

            case "\e[B":
            case "\eOB":
                $this->newer();
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
