<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * Keyword search: BM25 over the chunks and the headings above them.
 *
 * What it does well, nothing else does as well: exact references — an error
 * code, a product reference, a proper name, an option spelled out. What it
 * cannot do is match a question to a passage that says the same thing in
 * other words; that is what the embeddings phase is for, and what the
 * evaluation measures.
 */
final class Bm25Retriever implements Retriever
{
    public function __construct(
        private readonly DocIndex $index,
        private readonly bool $stemmed = false,
    ) {}

    public function name(): string
    {
        return $this->stemmed ? 'bm25+racines' : 'bm25';
    }

    public function retrieve(string $question, int $limit): array
    {
        $terms = QueryTerms::of($question);

        return $this->stemmed
            ? $this->index->bm25(QueryTerms::stems($terms), $limit, prefix: true)
            : $this->index->bm25($terms, $limit);
    }
}
