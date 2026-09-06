<?php

declare(strict_types=1);

namespace App\Rag;

use App\Rag\Parser\DocumentParser;
use App\Rag\Parser\HtmlParser;
use App\Rag\Parser\MarkdownParser;
use App\Rag\Parser\TextParser;

/**
 * The project's documents into the index — only what changed since last time.
 *
 * A file whose size and date are those it was indexed at is not read at all.
 * One touched but not changed is read and hashed, and left alone. Only a file
 * whose content changed is parsed and chunked again; one that disappeared
 * takes its chunks with it. So a sync on an unchanged project costs a listing
 * and a stat per file, which is what lets it run before every search rather
 * than on a schedule someone has to remember.
 */
final class Ingestor
{
    /** @var list<DocumentParser> */
    private readonly array $parsers;

    /** @param list<DocumentParser>|null $parsers */
    public function __construct(
        private readonly DocSource $source,
        private readonly Chunker $chunker,
        ?array $parsers = null,
    ) {
        $this->parsers = $parsers ?? [new MarkdownParser(), new TextParser(), new HtmlParser()];
    }

    public function sync(string $root, DocIndex $index): SyncReport
    {
        $report = new SyncReport();
        $files = $this->source->files($root);

        foreach ($files as $path) {
            $full = $root . '/' . $path;
            $size = (int) @filesize($full);
            $mtime = (int) @filemtime($full);
            $known = $index->document($path);

            if ($known !== null && $known['size'] === $size && $known['mtime'] === $mtime) {
                $report->unchanged++;
                continue;
            }

            $content = @file_get_contents($full);
            if ($content === false) {
                $report->failed[$path] = 'illisible';
                continue;
            }

            $hash = sha1($content);
            if ($known !== null && $known['hash'] === $hash) {
                $index->touch($path, $size, $mtime);
                $report->unchanged++;
                continue;
            }

            $parser = $this->parserFor($path);
            if ($parser === null) {
                continue;
            }

            try {
                $document = $parser->parse($path, self::utf8($content));
                $index->replace($path, $hash, $size, $mtime, $document, $this->chunker->chunk($document));
                $known === null ? $report->added++ : $report->updated++;
            } catch (\Throwable $e) {
                // One document that will not parse costs itself, not the index.
                $report->failed[$path] = $e->getMessage();
            }
        }

        foreach (array_diff($index->paths(), $files) as $gone) {
            $index->remove($gone);
            $report->removed++;
        }

        return $report;
    }

    private function parserFor(string $path): ?DocumentParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($path)) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * Documentation older than UTF-8 is still around — Latin-1 exports, Windows
     * notes. Read as UTF-8 it turns every accent into a character that matches
     * nothing; converted, it is searchable like the rest.
     */
    private static function utf8(string $content): string
    {
        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
}
