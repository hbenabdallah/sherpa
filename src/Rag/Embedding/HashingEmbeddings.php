<?php

declare(strict_types=1);

namespace App\Rag\Embedding;

use App\Rag\QueryTerms;

/**
 * A stand-in for a real embedding model, for tests that must not call one.
 *
 * Feature hashing of word stems: texts sharing words end up close. It knows no
 * synonym and no language — it is not here to be good, only to be
 * deterministic, so that storing, re-embedding, and fusing vectors can be
 * tested without a network. Whether real embeddings help is measured with a
 * real model, in tests/rag/eval_live.php.
 */
final class HashingEmbeddings implements EmbeddingProvider
{
    public int $calls = 0;
    public int $texts = 0;

    public function __construct(
        private readonly string $model = 'hachage-test',
        private readonly int $dimensions = 256,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function embed(array $texts): array
    {
        $this->calls++;
        $this->texts += count($texts);

        return array_map(function (string $text): array {
            $v = array_fill(0, $this->dimensions, 0.0);
            foreach (QueryTerms::stems(QueryTerms::of($text)) as $stem) {
                $v[crc32($stem) % $this->dimensions] += 1.0;
            }

            return Vectors::normalize($v);
        }, $texts);
    }
}
