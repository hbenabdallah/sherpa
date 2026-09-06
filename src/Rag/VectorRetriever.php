<?php

declare(strict_types=1);

namespace App\Rag;

use App\Rag\Embedding\EmbeddingProvider;
use App\Rag\Embedding\Vectors;

/**
 * Search by meaning: the question embedded, compared with every chunk.
 *
 * Every chunk, by brute force — no approximate index. A project's
 * documentation is hundreds of chunks, a few thousand at the very most, and an
 * exact comparison over that many is milliseconds; an ANN index would buy
 * nothing but approximation and a structure to keep in sync.
 *
 * Nothing is returned when the index holds vectors from another model than the
 * one configured: two models' vectors are not comparable, and a confident
 * wrong ranking is worse than none.
 */
final class VectorRetriever implements Retriever
{
    public function __construct(
        private readonly DocIndex $index,
        private readonly EmbeddingProvider $embeddings,
    ) {}

    public function name(): string
    {
        return 'vectors';
    }

    public function retrieve(string $question, int $limit): array
    {
        $model = $this->embeddings->model();
        if ($model === '' || $this->index->embeddingModel() !== $model) {
            return [];
        }

        $vectors = $this->index->vectors();
        if ($vectors === []) {
            return [];
        }

        $query = $this->embeddings->embed([$question])[0] ?? null;
        if ($query === null) {
            return [];
        }

        $scores = [];
        foreach ($vectors as $id => $vector) {
            $scores[$id] = Vectors::dot($query, $vector);
        }
        arsort($scores);

        $hits = [];
        foreach (array_slice($scores, 0, $limit, preserve_keys: true) as $id => $score) {
            $chunk = $this->index->chunk((int) $id);
            if ($chunk !== null) {
                $hits[] = new Hit((int) $id, $chunk, $score, count($hits) + 1);
            }
        }

        return $hits;
    }
}
