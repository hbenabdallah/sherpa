<?php

declare(strict_types=1);

namespace App\Rag\Parser;

use App\Rag\Section;

/**
 * Lines into sections, for any format whose headings are lines of text.
 *
 * Markdown, reStructuredText and AsciiDoc differ only in how a heading is
 * spelled; what a section is — everything under a heading until the next one
 * of any level, placed under its parents — is the same everywhere, so it is
 * written once. Each format passes the function that recognises its headings.
 */
final class SectionBuilder
{
    /**
     * @param list<string>                                                          $lines
     * @param callable(int, list<string>): (array{level: int, text: string, lines: int}|null) $headingAt
     *        the heading starting at line $i, and how many lines it spans (two
     *        for an underlined one), or null
     * @param callable(string): bool|null $opensVerbatim a line that starts a
     *        block where headings mean nothing — a fenced code block — and the
     *        same line closes it
     *
     * @return list<Section>
     */
    public static function build(array $lines, callable $headingAt, ?callable $opensVerbatim = null): array
    {
        $sections = [];
        $stack = [];               // level => heading text
        $buffer = [];
        $start = 1;
        $fence = null;

        $flush = function (int $end) use (&$sections, &$buffer, &$stack, &$start): void {
            // Blank lines under a heading are not the section: they are
            // dropped, and the section starts where its text does — otherwise
            // every citation points a line or two above what it cites.
            $lead = 0;
            while ($lead < count($buffer) && trim($buffer[$lead]) === '') {
                $lead++;
            }

            $text = trim(implode("\n", array_slice($buffer, $lead)), "\n");
            if (trim($text) !== '' || $stack !== []) {
                $sections[] = new Section(array_values($stack), $text, $start + $lead, max($start + $lead, $end));
            }
            $buffer = [];
        };

        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];

            if ($opensVerbatim !== null) {
                if ($fence !== null) {
                    $buffer[] = $line;
                    if (str_starts_with(ltrim($line), $fence)) {
                        $fence = null;
                    }
                    continue;
                }
                if ($opensVerbatim($line)) {
                    $fence = substr(ltrim($line), 0, 3);
                    $buffer[] = $line;
                    continue;
                }
            }

            $heading = $headingAt($i, $lines);
            if ($heading === null) {
                $buffer[] = $line;
                continue;
            }

            $flush($i);

            foreach (array_keys($stack) as $level) {
                if ($level >= $heading['level']) {
                    unset($stack[$level]);
                }
            }
            $stack[$heading['level']] = $heading['text'];
            ksort($stack);

            $i += $heading['lines'] - 1;
            $start = $i + 2;
        }

        $flush(count($lines));

        // A heading with nothing under it before the next one says where the
        // next section sits, not something of its own: it lives on in the
        // path of the sections below, and an empty section is dropped.
        return array_values(array_filter($sections, static fn(Section $s) => trim($s->text) !== ''));
    }
}
