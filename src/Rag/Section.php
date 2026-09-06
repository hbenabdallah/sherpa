<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * One section of a document: what sits under a heading, until the next one.
 *
 * The headings are kept as a path — "Refunds › Times" — because a
 * passage means little out of its section: "30 jours" is an answer only once
 * it is known what the 30 days are for.
 */
final class Section
{
    /**
     * @param list<string> $headings from the outermost heading to this one
     */
    public function __construct(
        public readonly array $headings,
        public readonly string $text,
        public readonly int $startLine,
        public readonly int $endLine,
    ) {}
}
