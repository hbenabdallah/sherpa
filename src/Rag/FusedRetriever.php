<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * Several retrievers, their rankings merged by Reciprocal Rank Fusion.
 *
 * Each chunk scores the sum of 1 / (k + rank) over the lists it appears in.
 * Ranks, not scores: a BM25 score and a cosine similarity are not on the same
 * scale, and no weighting between them survives a change of corpus. A chunk
 * ranked well by both strategies beats one ranked first by only one — which is
 * the whole point of asking two questions of different kinds, and also why
 * each kind should get one vote: two keyword rankings beside one of vectors
 * outvote it (see DocSearch::retriever()).
 *
 * k = 60 is the constant of the original paper, and nothing here has measured a
 * reason to move it.
 */
final class FusedRetriever implements Retriever
{
    private const K = 60;

    /** How deep each retriever is read before fusing: a candidate pool, not an answer. */
    private const CANDIDATES = 30;

    /** @param list<Retriever> $retrievers */
    public function __construct(private readonly array $retrievers) {}

    public function name(): string
    {
        return implode('+', array_map(static fn(Retriever $r) => $r->name(), $this->retrievers));
    }

    public function retrieve(string $question, int $limit): array
    {
        $scores = [];
        $chunks = [];

        foreach ($this->retrievers as $retriever) {
            foreach ($retriever->retrieve($question, max($limit, self::CANDIDATES)) as $hit) {
                $scores[$hit->id] = ($scores[$hit->id] ?? 0.0) + 1 / (self::K + $hit->rank);
                $chunks[$hit->id] = $hit->chunk;
            }
        }

        arsort($scores);

        $hits = [];
        foreach (array_slice($scores, 0, $limit, preserve_keys: true) as $id => $score) {
            $hits[] = new Hit((int) $id, $chunks[$id], $score, count($hits) + 1);
        }

        return $hits;
    }
}
