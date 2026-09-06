<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * Sections into chunks, cut along the document's own seams: fixed-size cutting
 * splits a sentence or a table down the middle and leaves both halves
 * meaningless. A section is the unit, a long one is cut at its paragraphs,
 * tables and fenced blocks are never cut, and each chunk repeats about an
 * eighth of the one before so a straddling sentence is whole somewhere.
 *
 * Sizes in characters, a quarter of a token being close enough for a budget.
 */
final class Chunker
{
    public const TARGET = 1400;
    public const MAX = 2800;
    public const OVERLAP = 170;
    public const PARENT_MAX = 6000;

    /** @return list<Chunk> */
    public function chunk(ParsedDocument $document): array
    {
        $chunks = [];
        $position = 0;

        foreach ($document->sections as $section) {
            $windows = $this->windows($this->blocks($section));
            $whole = $this->withHeading($section, $section->text);

            foreach ($windows as $index => $window) {
                // The whole section when it can be shown; otherwise the chunk
                // with its neighbours, which is the context it was cut from.
                $parent = mb_strlen($whole) <= self::PARENT_MAX
                    ? $whole
                    : $this->withHeading($section, $this->neighbourhood($windows, $index));

                $chunks[] = new Chunk(
                    path: $document->path,
                    title: $document->title,
                    headings: $section->headings,
                    text: $window['text'],
                    parent: $parent,
                    startLine: $window['start'],
                    endLine: $window['end'],
                    position: $position++,
                );
            }
        }

        return $chunks;
    }

    /**
     * A section's blocks: paragraphs, with a fenced block or a table kept as
     * one however long, since half of either means nothing.
     *
     * @return list<array{text: string, start: int, end: int}>
     */
    private function blocks(Section $section): array
    {
        $lines = explode("\n", $section->text);
        $blocks = [];
        $current = [];
        $from = null;
        $fence = null;

        $close = function (int $i) use (&$blocks, &$current, &$from, $section): void {
            // Trailing blanks carry nothing, not even in code — and left in,
            // a block no longer ends with the words that end it.
            $text = rtrim(trim(implode("\n", $current), "\n"));
            if (trim($text) !== '') {
                $blocks[] = ['text' => $text, 'start' => $section->startLine + $from, 'end' => $section->startLine + $i - 1];
            }
            $current = [];
            $from = null;
        };

        foreach ($lines as $i => $line) {
            if ($fence !== null) {
                $current[] = $line;
                if (str_starts_with(ltrim($line), $fence)) {
                    $fence = null;
                }
                continue;
            }

            if (preg_match('/^\s*(```|~~~)/', $line, $m) === 1) {
                $from ??= $i;
                $fence = $m[1];
                $current[] = $line;
                continue;
            }

            if (trim($line) === '') {
                $close($i);
                continue;
            }

            $from ??= $i;
            $current[] = $line;
        }

        $close(count($lines));

        // A block too large for any chunk, a fence or table included, is cut
        // at its lines after all: better a table in two chunks than a chunk
        // that swamps whatever it is shown with.
        $sized = [];
        foreach ($blocks as $block) {
            array_push($sized, ...$this->fit($block));
        }

        return $sized;
    }

    /**
     * @param array{text: string, start: int, end: int} $block
     *
     * @return list<array{text: string, start: int, end: int}>
     */
    private function fit(array $block): array
    {
        if (mb_strlen($block['text']) <= self::MAX) {
            return [$block];
        }

        $pieces = [];
        $current = '';
        $start = $block['start'];

        foreach (explode("\n", $block['text']) as $offset => $line) {
            // A single line longer than a chunk — a minified paragraph — is
            // cut where it must be.
            foreach (mb_str_split($line, self::MAX) ?: [''] as $part) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($part) + 1 > self::TARGET) {
                    $pieces[] = ['text' => $current, 'start' => $start, 'end' => $block['start'] + $offset];
                    $current = '';
                    $start = $block['start'] + $offset;
                }
                $current .= ($current === '' ? '' : "\n") . $part;
            }
        }

        if (trim($current) !== '') {
            $pieces[] = ['text' => $current, 'start' => $start, 'end' => $block['end']];
        }

        return $pieces;
    }

    /**
     * Blocks gathered into chunks around the target size, each starting with
     * the last block or two of the one before, within the overlap.
     *
     * @param list<array{text: string, start: int, end: int}> $blocks
     *
     * @return list<array{text: string, start: int, end: int}>
     */
    private function windows(array $blocks): array
    {
        $windows = [];
        $current = [];
        $size = 0;

        foreach ($blocks as $block) {
            $length = mb_strlen($block['text']);

            if ($current !== [] && $size + $length > self::TARGET) {
                $windows[] = $this->join($current);

                $carried = [];
                $carriedSize = 0;
                for ($i = count($current) - 1; $i >= 0; $i--) {
                    $carriedSize += mb_strlen($current[$i]['text']);
                    if ($carriedSize > self::OVERLAP) {
                        break;
                    }
                    array_unshift($carried, $current[$i]);
                }

                // Paragraphs are usually longer than the overlap, and then no
                // whole block fits: carrying nothing would leave no overlap at
                // all. The last sentences of the last paragraph go instead.
                if ($carried === []) {
                    $last = $current[count($current) - 1];
                    $tail = $this->tail($last['text']);
                    if ($tail !== '') {
                        $carried = [['text' => $tail, 'start' => $last['end'], 'end' => $last['end']]];
                    }
                }

                $current = $carried;
                $size = array_sum(array_map(static fn(array $b) => mb_strlen($b['text']), $carried));
            }

            $current[] = $block;
            $size += $length;
        }

        if ($current !== []) {
            $windows[] = $this->join($current);
        }

        return $windows;
    }

    /**
     * The end of a paragraph, in whole sentences, within the overlap — or in
     * whole words when one sentence is longer than that. Nothing from a table
     * or a code block: half a row or half a function is noise at the top of
     * the next chunk, not context.
     */
    private function tail(string $text): string
    {
        if (preg_match('/^\s*(\||```|~~~)/', $text) === 1) {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?…])\s+/u', trim($text)) ?: [];
        $tail = '';
        for ($i = count($sentences) - 1; $i >= 0; $i--) {
            $candidate = $sentences[$i] . ($tail === '' ? '' : ' ' . $tail);
            if (mb_strlen($candidate) > self::OVERLAP) {
                break;
            }
            $tail = $candidate;
        }

        if ($tail !== '') {
            return $tail;
        }

        $words = preg_split('/\s+/u', trim($text)) ?: [];
        for ($i = count($words) - 1; $i >= 0; $i--) {
            $candidate = $words[$i] . ($tail === '' ? '' : ' ' . $tail);
            if (mb_strlen($candidate) > self::OVERLAP) {
                break;
            }
            $tail = $candidate;
        }

        return $tail;
    }

    /**
     * @param list<array{text: string, start: int, end: int}> $blocks
     *
     * @return array{text: string, start: int, end: int}
     */
    private function join(array $blocks): array
    {
        return [
            'text'  => implode("\n\n", array_column($blocks, 'text')),
            'start' => $blocks[0]['start'],
            'end'   => $blocks[count($blocks) - 1]['end'],
        ];
    }

    /** @param list<array{text: string, start: int, end: int}> $windows */
    private function neighbourhood(array $windows, int $index): string
    {
        $text = $windows[$index]['text'];

        foreach ([$index - 1, $index + 1] as $neighbour) {
            if (!isset($windows[$neighbour])) {
                continue;
            }
            $candidate = $neighbour < $index
                ? $windows[$neighbour]['text'] . "\n\n" . $text
                : $text . "\n\n" . $windows[$neighbour]['text'];

            if (mb_strlen($candidate) <= self::PARENT_MAX) {
                $text = $candidate;
            }
        }

        return $text;
    }

    private function withHeading(Section $section, string $text): string
    {
        return $section->headings === []
            ? $text
            : str_repeat('#', count($section->headings)) . ' ' . $section->headings[count($section->headings) - 1] . "\n\n" . $text;
    }
}
