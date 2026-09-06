<?php

namespace App\TUI;

class MarkdownRenderer
{
    private bool $inCodeBlock = false;
    private string $codeLang = '';

    /**
     * A table's rows, held until the table ends: its columns can only be
     * sized once every row is known, and a reply streams line by line.
     *
     * @var list<string>
     */
    private array $table = [];

    /** Narrowest a column is squeezed to before a table overflows instead. */
    private const MIN_COLUMN = 6;

    /** Widest a table is drawn, however wide the terminal. */
    private const MAX_TABLE_WIDTH = 120;

    /** @param int|null $width the terminal's width; asked of the terminal when null */
    public function __construct(private readonly ?int $width = null) {}

    public function render(string $markdown): string
    {
        $this->inCodeBlock = false;
        $this->codeLang = '';

        $this->table = [];

        $output = [];
        foreach (explode("\n", $markdown) as $line) {
            array_push($output, ...$this->feed($line));
        }
        array_push($output, ...$this->flushTable());

        return implode("\n", $output);
    }

    /**
     * Clear fence state between messages, so an unterminated ``` in one reply
     * does not swallow the next one.
     */
    public function reset(): void
    {
        $this->inCodeBlock = false;
        $this->codeLang = '';
        $this->table = [];
    }

    /**
     * End of the stream: the trailing partial line, and a table still being
     * held. Fence state is kept (unlike render(), which resets it).
     */
    public function renderRemainder(string $line): string
    {
        $output = $line === '' ? [] : $this->feed($line);
        array_push($output, ...$this->flushTable());

        return implode("\n", $output);
    }

    /**
     * Render incrementally (one token at a time), buffering lines.
     * Returns completed lines ready to print, keeping the last partial line.
     */
    public function renderIncremental(string &$buffer, string $newToken): string
    {
        $buffer .= $newToken;
        $lines = explode("\n", $buffer);
        $buffer = array_pop($lines); // keep last incomplete line

        $output = [];
        foreach ($lines as $line) {
            array_push($output, ...$this->feed($line));
        }

        return implode("\n", $output) . (empty($output) ? '' : "\n");
    }

    /**
     * One complete line in, the lines ready to print out: none while a table
     * is being gathered, the whole table once a line ends it.
     *
     * @return list<string>
     */
    private function feed(string $line): array
    {
        if (!$this->inCodeBlock && self::isTableRow($line)) {
            $this->table[] = $line;

            return [];
        }

        return [...$this->flushTable(), $this->renderLine($line)];
    }

    private static function isTableRow(string $line): bool
    {
        $line = trim($line);

        return strlen($line) > 1 && $line[0] === '|' && substr_count($line, '|') >= 2;
    }

    /** @return list<string> */
    private function flushTable(): array
    {
        if ($this->table === []) {
            return [];
        }

        $rows = $this->table;
        $this->table = [];

        return $this->drawTable($rows);
    }

    /**
     * Box-drawn, fitted to the terminal: columns wider than the room are
     * narrowed, widest first, and their cells wrapped by words.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function drawTable(array $lines): array
    {
        $rows = array_map(self::cells(...), $lines);

        // The |---|:---:| line: where the header ends, and how columns align.
        $aligns = [];
        $header = null;
        if (count($rows) >= 2 && self::isSeparator($rows[1])) {
            $aligns = array_map(fn(string $c) => str_ends_with($c, ':') ? (str_starts_with($c, ':') ? 'center' : 'right') : 'left', $rows[1]);
            $header = $rows[0];
            $rows = array_slice($rows, 2);
        } else {
            $rows = array_values(array_filter($rows, fn(array $r) => !self::isSeparator($r)));
        }

        $count = max(array_map('count', [...($header !== null ? [$header] : []), ...$rows, [0]]));
        if ($count === 0) {
            return [];
        }

        $pad = fn(array $r) => array_pad($r, $count, '');
        $header = $header !== null ? $pad($header) : null;
        $rows = array_map($pad, $rows);

        $widths = array_fill(0, $count, 3);
        foreach ([...($header !== null ? [$header] : []), ...$rows] as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], mb_strwidth($cell));
            }
        }
        $widths = $this->fit($widths);

        $border = fn(string $left, string $mid, string $right) => Terminal::GRAY . $left
            . implode($mid, array_map(fn(int $w) => str_repeat('─', $w + 2), $widths)) . $right . Terminal::RESET;

        $wrapped = false;
        $render = function (array $row, bool $bold) use ($widths, $aligns, &$wrapped): array {
            $cells = [];
            foreach ($row as $i => $cell) {
                $cells[$i] = self::wrap($cell, $widths[$i]);
            }
            $height = max(array_map('count', $cells));
            $wrapped = $wrapped || $height > 1;

            $out = [];
            for ($l = 0; $l < $height; $l++) {
                $parts = [];
                foreach ($cells as $i => $lines) {
                    $text = $lines[$l] ?? '';
                    $gap = $widths[$i] - mb_strwidth($text);
                    $text = match ($aligns[$i] ?? 'left') {
                        'right'  => str_repeat(' ', $gap) . $text,
                        'center' => str_repeat(' ', intdiv($gap, 2)) . $text . str_repeat(' ', $gap - intdiv($gap, 2)),
                        default  => $text . str_repeat(' ', $gap),
                    };
                    $parts[] = ' ' . ($bold ? Terminal::BOLD . $text . Terminal::RESET : $text) . ' ';
                }
                $bar = Terminal::GRAY . '│' . Terminal::RESET;
                $out[] = $bar . implode($bar, $parts) . $bar;
            }

            return $out;
        };

        $body = array_map(fn(array $row) => $render($row, false), $rows);

        $out = [$border('┌', '┬', '┐')];
        if ($header !== null) {
            array_push($out, ...$render($header, true));
            $out[] = $border('├', '┼', '┤');
        }
        foreach ($body as $i => $lines) {
            // Rows of several lines each read as one block only with a rule between them.
            if ($i > 0 && $wrapped) {
                $out[] = $border('├', '┼', '┤');
            }
            array_push($out, ...$lines);
        }
        $out[] = $border('└', '┴', '┘');

        return $out;
    }

    /**
     * Fit the table to the terminal by capping the widest columns at one
     * common width — the largest that fits — so the room lost is shared
     * rather than taken from one column. Never below MIN_COLUMN: past that
     * the table overflows rather than shred every word.
     *
     * @param list<int> $widths
     *
     * @return list<int>
     */
    private function fit(array $widths): array
    {
        // One column short of the terminal: a line that fills it exactly leaves
        // many terminals wrapping on the next character, and doubled rows when
        // the window is resized or the text copied. And no wider than a line
        // can be read across, however wide the screen.
        $terminal = $this->width ?? ((new Terminal())->size()[0] ?: 100);
        $room = min($terminal - 1, self::MAX_TABLE_WIDTH) - (3 * count($widths) + 1);

        if (array_sum($widths) <= $room) {
            return $widths;
        }

        $cap = max($widths);
        while ($cap > self::MIN_COLUMN && array_sum(array_map(fn(int $w) => min($w, $cap), $widths)) > $room) {
            $cap--;
        }

        return array_map(fn(int $w) => min($w, $cap), $widths);
    }

    /** @return list<string> */
    private static function cells(string $line): array
    {
        $line = trim(Terminal::plain($line, singleLine: true));
        $line = preg_replace('/^\||\|$/', '', $line) ?? $line;

        return array_map(fn(string $cell) => self::plainCell(trim(str_replace('\\|', '|', $cell))),
            preg_split('/(?<!\\\\)\|/', $line) ?: []);
    }

    /** Markdown inside a cell, reduced to its text: widths are counted on what shows. */
    private static function plainCell(string $cell): string
    {
        $cell = preg_replace('/<br\s*\/?>/i', ' ', $cell) ?? $cell;
        $cell = preg_replace('/\*\*(.+?)\*\*|__(.+?)__/', '$1$2', $cell) ?? $cell;
        $cell = preg_replace('/`([^`]+)`/', '$1', $cell) ?? $cell;

        return preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $cell) ?? $cell;
    }

    /** @param list<string> $row */
    private static function isSeparator(array $row): bool
    {
        return $row !== [] && array_filter($row, fn(string $c) => preg_match('/^:?-{2,}:?$/', $c) !== 1) === [];
    }

    /**
     * A cell's text in lines no wider than $width, cut between words, and
     * inside a word only when the word alone is wider.
     *
     * @return list<string>
     */
    private static function wrap(string $text, int $width): array
    {
        if (mb_strwidth($text) <= $width) {
            return [$text];
        }

        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            while (mb_strwidth($word) > $width) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $head = mb_strimwidth($word, 0, $width);
                $lines[] = $head;
                $word = mb_substr($word, mb_strlen($head));
            }

            $candidate = $line === '' ? $word : "{$line} {$word}";
            if (mb_strwidth($candidate) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    private function renderLine(string $line): string
    {
        // Model output is untrusted text like any other: it is free to contain
        // escape sequences, and a 7B model echoing back a file it just read will
        // happily reproduce them. Strip before adding our own colours — this is
        // the one choke point every rendered line passes through.
        $line = Terminal::plain($line, singleLine: true);

        // Code block fence
        if (preg_match('/^```(\w*)/', $line, $m)) {
            if (!$this->inCodeBlock) {
                $this->inCodeBlock = true;
                $this->codeLang = $m[1] ?? '';
                $lang = $this->codeLang ? " {$this->codeLang}" : '';
                return Terminal::BG_DARK . Terminal::GRAY . "┌── code{$lang} " . Terminal::RESET;
            } else {
                $this->inCodeBlock = false;
                $this->codeLang = '';
                return Terminal::BG_DARK . Terminal::GRAY . "└────────" . Terminal::RESET;
            }
        }

        if ($this->inCodeBlock) {
            return Terminal::BG_DARK . Terminal::CYAN . '  ' . $line . Terminal::RESET;
        }

        // Headings: the marks go, the level shows in the style.
        if (preg_match('/^(#{1,6})\s+(.+)/', $line, $m)) {
            $style = match (strlen($m[1])) {
                1       => Terminal::BOLD . Terminal::UNDERLINE . Terminal::LIME,
                2       => Terminal::BOLD . Terminal::LIME,
                default => Terminal::BOLD . Terminal::SLATE,
            };

            return $style . self::plainCell(rtrim($m[2], ' #')) . Terminal::RESET;
        }

        // Horizontal rule
        if (preg_match('/^---+$/', trim($line))) {
            return Terminal::GRAY . str_repeat('─', 60) . Terminal::RESET;
        }

        // Blockquote
        if (str_starts_with(ltrim($line), '> ')) {
            $content = substr(ltrim($line), 2);
            return Terminal::GRAY . '▌ ' . Terminal::ITALIC . $this->renderInline($content) . Terminal::RESET;
        }

        // Unordered list
        if (preg_match('/^(\s*)[*\-+]\s+(.+)/', $line, $m)) {
            $indent = str_repeat(' ', strlen($m[1]));
            return $indent . Terminal::YELLOW . '•' . Terminal::RESET . ' ' . $this->renderInline($m[2]);
        }

        // Ordered list
        if (preg_match('/^(\s*)(\d+)\.\s+(.+)/', $line, $m)) {
            $indent = str_repeat(' ', strlen($m[1]));
            return $indent . Terminal::YELLOW . $m[2] . '.' . Terminal::RESET . ' ' . $this->renderInline($m[3]);
        }

        return $this->renderInline($line);
    }

    private function renderInline(string $text): string
    {
        // Bold
        $text = preg_replace('/\*\*(.+?)\*\*/', Terminal::BOLD . '$1' . Terminal::RESET, $text);
        // Italic
        $text = preg_replace('/\*(.+?)\*/', Terminal::ITALIC . '$1' . Terminal::RESET, $text);
        // Inline code
        $text = preg_replace('/`([^`]+)`/', Terminal::BG_DARK . Terminal::CYAN . ' $1 ' . Terminal::RESET, $text);
        // Links [text](url) → just text
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', Terminal::UNDERLINE . '$1' . Terminal::RESET, $text);

        return $text;
    }
}
