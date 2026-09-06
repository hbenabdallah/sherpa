<?php

declare(strict_types=1);

namespace App\Rag\Embedding;

use App\Rag\DocIndex;

/**
 * Gives every chunk of the index a vector, reusing the ones already made.
 *
 * Incremental like the rest of the index: only chunks without a vector are
 * sent — those of documents added or changed since last time — so a project
 * pays for its documentation once, then for what changes. A different model
 * than the one the vectors were made with starts everything over.
 */
final class ChunkEmbedder
{
    /** Chunks per request to the provider. */
    private const BATCH = 32;

    /** @return int how many chunks were embedded now */
    public function embed(DocIndex $index, EmbeddingProvider $embeddings): int
    {
        $model = $embeddings->model();
        if ($model === '') {
            return 0;
        }

        if ($index->embeddingModel() !== $model) {
            $index->resetVectors($model);
        }

        $done = 0;
        while (($pending = $index->unembedded(self::BATCH)) !== []) {
            $vectors = $embeddings->embed(array_column($pending, 'text'));

            if (count($vectors) !== count($pending)) {
                throw new \RuntimeException("Embeddings: {$model} returned " . count($vectors) . ' vectors for ' . count($pending) . ' passages.');
            }

            $index->storeVectors(array_combine(array_column($pending, 'id'), $vectors));
            $done += count($pending);
        }

        return $done;
    }
}
