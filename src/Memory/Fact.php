<?php

namespace App\Memory;

use DateTimeImmutable;

final class Fact
{
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $value,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        /** Set when a later fact replaced this one; the row is kept regardless. */
        public readonly ?DateTimeImmutable $supersededAt = null,
        /** How often the model went looking for this fact on purpose. */
        public readonly int $recalledCount = 0,
        public readonly ?DateTimeImmutable $lastRecalledAt = null,
        /** Who wrote it, and what the session had read from outside by then; null before it was kept. */
        public readonly ?string $source = null,
    ) {}

    /** Written after the session read a web page or an MCP answer: possibly that text's doing. */
    public function afterUntrusted(): bool
    {
        return $this->source !== null && str_contains($this->source, self::AFTER);
    }

    /** How a source says what had been read from outside. */
    public const AFTER = ', after reading ';

    public function isCurrent(): bool
    {
        return $this->supersededAt === null;
    }
}
