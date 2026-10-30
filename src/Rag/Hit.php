<?php

declare(strict_types=1);

namespace App\Rag;

/** A chunk a search returned, with how well it scored and where it ranked. */
final readonly class Hit
{
    public function __construct(
        public int $id,
        public Chunk $chunk,
        public float $score,
        public int $rank,
    ) {}
}
