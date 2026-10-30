<?php

declare(strict_types=1);

namespace App\Rag;

/** A document as a parser read it: a title and its sections, in order. */
final readonly class ParsedDocument
{
    /** @param list<Section> $sections */
    public function __construct(
        public string $path,
        public string $title,
        public string $type,
        public array $sections,
    ) {}
}
