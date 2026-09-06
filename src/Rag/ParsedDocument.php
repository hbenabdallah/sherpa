<?php

declare(strict_types=1);

namespace App\Rag;

/** A document as a parser read it: a title and its sections, in order. */
final class ParsedDocument
{
    /** @param list<Section> $sections */
    public function __construct(
        public readonly string $path,
        public readonly string $title,
        public readonly string $type,
        public readonly array $sections,
    ) {}
}
