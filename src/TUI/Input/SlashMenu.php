<?php

declare(strict_types=1);

namespace App\TUI\Input;

use App\TUI\Terminal;

/**
 * The list under the prompt while a command is being typed: "/" shows every
 * command and skill, "/mo" the ones it could still become. No terminal in
 * here — text goes in, rows come out — like LineBuffer beside it.
 *
 * Open only while the line is a lone word starting with "/", with the cursor
 * at its end: past the first space it is arguments, and a menu over them
 * would be in the way.
 */
final class SlashMenu
{
    private const ROWS = 8;

    /** @var list<array{name: string, hint: string, description: string}> */
    private array $matches = [];
    private int $selected = 0;
    private string $for = '';
    private ?string $dismissedFor = null;

    /**
     * @param \Closure(): list<array{name: string, hint: string, description: string}> $entries
     *        read at each keystroke: a skill added mid-session shows at once
     */
    public function __construct(private readonly \Closure $entries) {}

    public function update(string $text, bool $cursorAtEnd): void
    {
        if ($text !== $this->for) {
            $this->for = $text;
            $this->selected = 0;
        }

        $this->matches = [];
        if (!$cursorAtEnd || !str_starts_with($text, '/') || str_contains($text, ' ') || $this->dismissedFor === $text) {
            return;
        }

        $typed = strtolower($text);
        $starts = [];
        $contains = [];
        foreach (($this->entries)() as $entry) {
            $name = strtolower($entry['name']);
            if (str_starts_with($name, $typed)) {
                $starts[] = $entry;
            } elseif (strlen($typed) > 1 && str_contains($name, substr($typed, 1))) {
                $contains[] = $entry;
            }
        }

        $this->matches = [...$starts, ...$contains];
        $this->selected = min($this->selected, max(0, count($this->matches) - 1));
    }

    public function isOpen(): bool
    {
        return $this->matches !== [];
    }

    public function up(): void
    {
        $count = count($this->matches);
        $this->selected = $count === 0 ? 0 : ($this->selected - 1 + $count) % $count;
    }

    public function down(): void
    {
        $count = count($this->matches);
        $this->selected = $count === 0 ? 0 : ($this->selected + 1) % $count;
    }

    /** Closed until the line changes: Escape, for a menu in the way. */
    public function dismiss(): void
    {
        $this->dismissedFor = $this->for;
        $this->matches = [];
    }

    /** @return array{name: string, hint: string, description: string}|null */
    public function current(): ?array
    {
        return $this->matches[$this->selected] ?? null;
    }

    /**
     * What the line becomes when the entry is taken: the name, and a space
     * after it when arguments are expected, so the next key typed is one.
     */
    public static function completion(array $entry): string
    {
        return $entry['name'] . ($entry['hint'] !== '' ? ' ' : '');
    }

    /** Whether taking it with Enter should run it, rather than wait for arguments. */
    public static function runsAlone(array $entry): bool
    {
        return $entry['hint'] === '' || str_starts_with($entry['hint'], '[');
    }

    /** @return list<string> the rows to draw, the selected one highlighted */
    public function rows(int $width): array
    {
        $count = count($this->matches);
        if ($count === 0) {
            return [];
        }

        $first = max(0, min($this->selected - intdiv(self::ROWS, 2), $count - self::ROWS));
        $shown = array_slice($this->matches, $first, self::ROWS, true);
        $nameWidth = max(array_map(fn($e) => mb_strwidth($e['name'] . ($e['hint'] !== '' ? ' ' . $e['hint'] : '')), $shown));

        $rows = [];
        foreach ($shown as $i => $entry) {
            $label = $entry['name'] . ($entry['hint'] !== '' ? ' ' . $entry['hint'] : '');
            $text = '  ' . $label . str_repeat(' ', $nameWidth - mb_strwidth($label)) . '   ' . $entry['description'];
            $text = mb_strimwidth(Terminal::plain($text, singleLine: true), 0, max(10, $width - 1), '…');

            $rows[] = $i === $this->selected
                ? "\e[7m" . $text . Terminal::RESET
                : Terminal::GRAY . $text . Terminal::RESET;
        }

        if ($count > self::ROWS) {
            $rows[] = Terminal::GRAY . '  ' . ($this->selected + 1) . '/' . $count . '  ↑↓ choose · Tab complete · Enter run · Esc close' . Terminal::RESET;
        } else {
            $rows[] = Terminal::GRAY . '  ↑↓ choose · Tab complete · Enter run · Esc close' . Terminal::RESET;
        }

        return $rows;
    }
}
