<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * One strategy, and another when it cannot answer.
 *
 * The search by meaning needs the provider to embed each question, and a
 * provider can be down, slow, or out of quota. Keywords need nothing. So a search that fails or comes back empty
 * one way is asked again the other way, rather than telling the model the
 * documentation has nothing to say.
 */
final class FallbackRetriever implements Retriever
{
    private ?string $lastError = null;

    public function __construct(
        private readonly Retriever $primary,
        private readonly Retriever $fallback,
    ) {}

    public function name(): string
    {
        return $this->primary->name() . ', else ' . $this->fallback->name();
    }

    public function retrieve(string $question, int $limit): array
    {
        $this->lastError = null;

        try {
            $hits = $this->primary->retrieve($question, $limit);
            if ($hits !== []) {
                return $hits;
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }

        return $this->fallback->retrieve($question, $limit);
    }

    /** Why the primary strategy failed last time, if it did. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
