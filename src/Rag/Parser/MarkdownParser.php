<?php

declare(strict_types=1);

namespace App\Rag\Parser;

use App\Rag\ParsedDocument;

/**
 * Markdown, the format most project documentation is written in.
 *
 * Headings of both kinds — "# Titre" and a line underlined with === or ---.
 * A "#" inside a fenced code block is a shell comment, not a heading, so fences
 * are followed. Front matter is read for its title and kept out of the text.
 * Tables, lists and code are left exactly as written: Markdown is already the
 * form the model reads best.
 */
final class MarkdownParser implements DocumentParser
{
    private const EXTENSIONS = ['md', 'markdown', 'mdx'];

    public function supports(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    public function parse(string $path, string $content): ParsedDocument
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $title = null;

        // Front matter: blanked rather than removed, so line numbers still
        // point at the right place in the file.
        if (($lines[0] ?? '') === '---') {
            for ($i = 1, $n = count($lines); $i < $n; $i++) {
                if (preg_match('/^title:\s*["\']?(.+?)["\']?\s*$/', $lines[$i], $m) === 1) {
                    $title = $m[1];
                }
                if ($lines[$i] === '---' || $lines[$i] === '...') {
                    for ($j = 0; $j <= $i; $j++) {
                        $lines[$j] = '';
                    }
                    break;
                }
            }
        }

        $sections = SectionBuilder::build(
            $lines,
            static function (int $i, array $lines): ?array {
                $line = $lines[$i];

                if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m) === 1) {
                    return ['level' => strlen($m[1]), 'text' => trim($m[2]), 'lines' => 1];
                }

                // Setext: text underlined. "---" under a blank line is a rule,
                // and under a list item it is not a heading either.
                $next = $lines[$i + 1] ?? '';
                if (trim($line) !== '' && !preg_match('/^\s*([-*+]|\d+\.|>|\|)\s/', $line)
                    && preg_match('/^(=+|-+)\s*$/', $next, $u) === 1) {
                    return ['level' => $u[1][0] === '=' ? 1 : 2, 'text' => trim($line), 'lines' => 2];
                }

                return null;
            },
            static fn(string $line) => preg_match('/^\s*(```|~~~)/', $line) === 1,
        );

        $title ??= self::firstHeading($sections) ?? pathinfo($path, PATHINFO_FILENAME);

        return new ParsedDocument($path, $title, 'markdown', $sections);
    }

    /** @param list<\App\Rag\Section> $sections */
    private static function firstHeading(array $sections): ?string
    {
        foreach ($sections as $section) {
            if ($section->headings !== []) {
                return $section->headings[0];
            }
        }

        return null;
    }
}
