<?php

declare(strict_types=1);

namespace App\Rag\Embedding;

/**
 * What detection concluded. Settled answers are recorded — a model, or none —
 * and an undetermined one is not, so the next launch looks again.
 */
final class EmbeddingDetection
{
    private function __construct(
        public readonly ?string $model,
        public readonly bool $settled,
        public readonly string $reason,
    ) {}

    public static function found(string $model): self
    {
        return new self($model, true, '');
    }

    public static function none(string $reason): self
    {
        return new self(null, true, $reason);
    }

    public static function undetermined(string $reason): self
    {
        return new self(null, false, $reason);
    }
}
