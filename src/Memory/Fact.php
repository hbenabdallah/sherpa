<?php

namespace App\Memory;

final class Fact
{
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $value,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        /** Set when a later fact replaced this one; the row is kept regardless. */
        public readonly ?\DateTimeImmutable $supersededAt = null,
    ) {}

    public function isCurrent(): bool
    {
        return $this->supersededAt === null;
    }
}
