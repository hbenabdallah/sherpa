<?php

declare(strict_types=1);

namespace App\Rag\Parser;

use App\Rag\ParsedDocument;
use App\Rag\Section;

/**
 * HTML pages: generated documentation, exported wikis, static sites.
 *
 * Read through the DOM, not by stripping tags. Stripped flat, a table becomes
 * a run of cells nobody can reassemble — "Paris 2 jours Lyon 3 jours" — when
 * it is often the densest information on the page; here it becomes a Markdown
 * table the model reads row by row. Navigation, scripts and footers are page
 * furniture, and dropped before anything else is read.
 */
final class HtmlParser implements DocumentParser
{
    private const FURNITURE = ['script', 'style', 'noscript', 'nav', 'footer', 'template', 'svg'];

    public function supports(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['html', 'htm'], true);
    }

    public function parse(string $path, string $content): ParsedDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The XML declaration is how libxml is told the page is UTF-8 when the
        // page does not say so itself; without it, accents come out mangled.
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $content, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (self::FURNITURE as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $title = trim((string) ($dom->getElementsByTagName('title')->item(0)?->textContent ?? ''));

        $sections = [];
        $stack = [];
        $blocks = [];
        $start = 1;

        $flush = function (int $end) use (&$sections, &$stack, &$blocks, &$start): void {
            if ($blocks !== []) {
                $sections[] = new Section(array_values($stack), implode("\n\n", $blocks), $start, max($start, $end));
            }
            $blocks = [];
        };

        $body = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;
        if ($body !== null) {
            $this->walk($body, function (\DOMElement $node) use (&$stack, &$blocks, &$start, $flush, &$title): bool {
                $tag = strtolower($node->nodeName);

                if (preg_match('/^h([1-6])$/', $tag, $m) === 1) {
                    $flush($node->getLineNo() - 1);
                    $level = (int) $m[1];
                    foreach (array_keys($stack) as $l) {
                        if ($l >= $level) {
                            unset($stack[$l]);
                        }
                    }
                    $text = self::text($node);
                    $stack[$level] = $text;
                    ksort($stack);
                    $start = $node->getLineNo();
                    if ($title === '') {
                        $title = $text;
                    }

                    return false;
                }

                $block = match ($tag) {
                    'p', 'dd', 'dt', 'blockquote', 'figcaption' => self::text($node),
                    'li'    => '- ' . self::text($node),
                    'pre'   => "```\n" . rtrim($node->textContent) . "\n```",
                    'table' => self::table($node),
                    default => null,
                };

                if ($block === null) {
                    return true;   // not a block of its own: look inside
                }

                if (trim($block) !== '') {
                    $blocks[] = $block;
                }

                return false;
            });
        }

        $flush(substr_count($content, "\n") + 1);

        return new ParsedDocument($path, $title !== '' ? $title : pathinfo($path, PATHINFO_FILENAME), 'html', $sections);
    }

    /**
     * Depth first, in document order. $visit answers whether to go inside.
     *
     * @param callable(\DOMElement): bool $visit
     */
    private function walk(\DOMNode $node, callable $visit): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $visit($child)) {
                $this->walk($child, $visit);
            }
        }
    }

    private static function text(\DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    /** A table as Markdown: a header row, its rule, then one line per row. */
    private static function table(\DOMElement $table): string
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = str_replace('|', '\|', self::text($cell));
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        $width = max(array_map('count', $rows));
        $line = static fn(array $cells) => '| ' . implode(' | ', array_pad($cells, $width, '')) . ' |';

        $out = [$line($rows[0]), '|' . str_repeat(' --- |', $width)];
        foreach (array_slice($rows, 1) as $row) {
            $out[] = $line($row);
        }

        return implode("\n", $out);
    }
}
