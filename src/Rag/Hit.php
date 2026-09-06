<?php

declare(strict_types=1);

namespace App\Rag;

/** A chunk a search returned, with how well it scored and where it ranked. */
final class Hit
{
    public function __construct(
        public readonly int $id,
        public readonly Chunk $chunk,
        public readonly float $score,
        public readonly int $rank,
    ) {}
}
