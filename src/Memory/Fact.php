<?php

namespace App\Memory;

use DateTimeImmutable;

final readonly class Fact
{
    public function __construct(
        public int $id,
        public string $key,
        public string $value,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /** Set when a later fact replaced this one; the row is kept regardless. */
        public ?DateTimeImmutable $supersededAt = null,
        /** How often the model went looking for this fact on purpose. */
        public int $recalledCount = 0,
        public ?DateTimeImmutable $lastRecalledAt = null,
        /** Who wrote it, and what the session had read from outside by then; null before it was kept. */
        public ?string $source = null,
    ) {}

    /** Written after the session read a web page or an MCP answer: possibly that text's doing. */
    public function afterUntrusted(): bool
    {
        return $this->source !== null && str_contains($this->source, self::AFTER);
    }

    /** How a source says what had been read from outside. */
    public const string AFTER = ', after reading ';

    public function isCurrent(): bool
    {
        return !$this->supersededAt instanceof DateTimeImmutable;
    }
}
