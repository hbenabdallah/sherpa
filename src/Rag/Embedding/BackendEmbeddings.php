<?php

declare(strict_types=1);

namespace App\Rag\Embedding;

use App\Config\ModelResolver;
use App\Platform\SwitchablePlatform;

/**
 * Embeddings from the backend in force, with the model this machine chose for
 * it — resolved at each call, so /backend moves them along with the chat.
 */
class BackendEmbeddings implements EmbeddingProvider
{
    public function __construct(
        private readonly SwitchablePlatform $platform,
        private readonly ModelResolver $models,
    ) {}

    public function model(): string
    {
        return $this->models->embeddingModel($this->platform->backend());
    }

    public function embed(array $texts): array
    {
        $model = $this->model();
        if ($model === '' || $texts === []) {
            return [];
        }

        return array_map(Vectors::normalize(...), $this->platform->embed($texts, $model));
    }
}
