<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * A way of finding the chunks that answer a question.
 *
 * The port every strategy plugs into — keywords, vectors, both fused — so that
 * the evaluation can put them side by side on the same questions, and the one
 * that wins is the one the tool uses.
 */
interface Retriever
{
    public function name(): string;

    /** @return list<Hit> best first */
    public function retrieve(string $question, int $limit): array;
}
