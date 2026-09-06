<?php

declare(strict_types=1);

namespace App\Rag\Parser;

use App\Rag\ParsedDocument;

/**
 * Plain text, reStructuredText and AsciiDoc.
 *
 * Plain text has no headings to follow: it is one section, and the chunker
 * cuts it at its paragraphs. The two others do, each spelled its own way —
 * AsciiDoc with leading "=", reStructuredText with a line underlined, whose
 * level is set by the order in which each underline character first appears.
 */
final class TextParser implements DocumentParser
{
    public function supports(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['txt', 'text', 'rst', 'adoc', 'asciidoc'], true);
    }

    public function parse(string $path, string $content): ParsedDocument
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        [$type, $headingAt, $verbatim] = match ($extension) {
            'adoc', 'asciidoc' => ['asciidoc', self::asciidoc(...), static fn(string $l) => preg_match('/^(----|\.\.\.\.)\s*$/', $l) === 1],
            'rst'              => ['rst', self::restructured(), null],
            default            => ['text', static fn() => null, null],
        };

        $sections = SectionBuilder::build($lines, $headingAt, $verbatim);

        $title = pathinfo($path, PATHINFO_FILENAME);
        foreach ($sections as $section) {
            if ($section->headings !== []) {
                $title = $section->headings[0];
                break;
            }
        }

        return new ParsedDocument($path, $title, $type, $sections);
    }

    /** @param list<string> $lines */
    private static function asciidoc(int $i, array $lines): ?array
    {
        if (preg_match('/^(={1,6})\s+(.+?)\s*$/', $lines[$i], $m) === 1) {
            return ['level' => strlen($m[1]), 'text' => $m[2], 'lines' => 1];
        }

        return null;
    }

    /**
     * reStructuredText decides nothing by character: whichever underline comes
     * first is level one, the next new one level two. So the order is learned
     * while reading.
     */
    private static function restructured(): callable
    {
        $levels = [];

        return static function (int $i, array $lines) use (&$levels): ?array {
            $text = rtrim($lines[$i]);
            $under = rtrim($lines[$i + 1] ?? '');

            if (trim($text) === '' || $under === '' || preg_match('/^([=\-~^"\'`#*+.:_])\1*$/', $under, $m) !== 1
                || mb_strlen($under) < mb_strlen(trim($text))) {
                return null;
            }

            $char = $m[1];
            if (!isset($levels[$char])) {
                $levels[$char] = count($levels) + 1;
            }

            return ['level' => $levels[$char], 'text' => trim($text), 'lines' => 2];
        };
    }
}
