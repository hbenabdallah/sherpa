<?php

declare(strict_types=1);

namespace App\Rag\Embedding;

use App\Platform\EmbeddingBackend;

/** A backend and one model, named once — for evaluations that compare models. */
final class FixedEmbeddings implements EmbeddingProvider
{
    public function __construct(
        private readonly EmbeddingBackend $backend,
        private readonly string $model,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function embed(array $texts): array
    {
        return $texts === [] ? [] : array_map(Vectors::normalize(...), $this->backend->embed($texts, $this->model));
    }
}
