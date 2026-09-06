<?php

declare(strict_types=1);

namespace App\Rag\Parser;

use App\Rag\ParsedDocument;

/**
 * A source format, read into sections that keep its structure.
 *
 * This is where most retrieval failures begin: text extracted flat loses the
 * headings that say what a passage is about, and turns a table into a stream
 * of cells nobody can read back. Every parser here keeps headings as headings
 * and tables as Markdown tables.
 */
interface DocumentParser
{
    /** Whether this parser reads files like $path, judged by its name. */
    public function supports(string $path): bool;

    public function parse(string $path, string $content): ParsedDocument;
}
