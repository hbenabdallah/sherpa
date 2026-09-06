<?php

declare(strict_types=1);

namespace App\Rag\Embedding;

/**
 * The little vector arithmetic the index needs, and how vectors are stored.
 *
 * Every vector is scaled to unit length once, when it is made: cosine
 * similarity is then a plain dot product, which is all a search computes, a
 * few thousand times per question. Stored as packed float32 — 4 KB for 1 024
 * dimensions — which is half of what float64 would take, for precision no
 * ranking can tell apart.
 */
final class Vectors
{
    /** @param list<float> $v @return list<float> */
    public static function normalize(array $v): array
    {
        $norm = sqrt(array_sum(array_map(static fn(float $x) => $x * $x, $v)));

        return $norm > 0 ? array_map(static fn(float $x) => $x / $norm, $v) : $v;
    }

    /** @param list<float> $a @param list<float> $b */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $x) {
            $sum += $x * ($b[$i] ?? 0.0);
        }

        return $sum;
    }

    /** @param list<float> $v */
    public static function pack(array $v): string
    {
        return pack('g*', ...$v);
    }

    /** @return list<float> */
    public static function unpack(string $blob): array
    {
        return array_values(unpack('g*', $blob) ?: []);
    }
}
