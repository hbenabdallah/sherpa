<?php

declare(strict_types=1);

namespace App\Rag\Embedding;

/**
 * Text into vectors, such that texts meaning the same thing land close
 * together — in whatever words, and in whatever language.
 *
 * The port the text calls EmbeddingProvider. Models change every few months,
 * and a model is only ever compared with itself: vectors from two models live
 * in two spaces that have nothing to do with each other. So the model's name
 * travels with every vector stored, and a change of model is a re-embedding.
 */
interface EmbeddingProvider
{
    /** The model in use, or '' when none is configured: keywords only. */
    public function model(): string;

    /**
     * One unit-length vector per text, in order.
     *
     * @param list<string> $texts
     *
     * @return list<list<float>>
     */
    public function embed(array $texts): array;
}
