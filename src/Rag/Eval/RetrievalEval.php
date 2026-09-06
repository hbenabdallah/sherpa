<?php

declare(strict_types=1);

namespace App\Rag\Eval;

use App\Rag\Hit;
use App\Rag\Retriever;

/**
 * Did the right passage come back, and how high? Measured apart from any
 * answer, because that is where RAG fails: a model cannot cite what it was
 * never shown. Three numbers per strategy — recall@1, recall@k, MRR — each
 * broken down by kind of question, since a strategy perfect on exact references
 * and blind to rephrasing averages to a number describing neither. Without
 * them, every change of chunking, model or ranking is a bet.
 */
final class RetrievalEval
{
    /** How deep MRR looks: a passage found at rank 30 was not found. */
    private const DEPTH = 10;

    /**
     * @param list<array{kind: string, q: string, expect: list<array{path: string, section: string}>}> $questions
     *
     * @return array{
     *   overall: array{n: int, r1: float, rk: float, mrr: float},
     *   kinds: array<string, array{n: int, r1: float, rk: float, mrr: float}>,
     *   misses: list<array{kind: string, q: string, top: string}>
     * }
     */
    public function run(Retriever $retriever, array $questions, int $k = 5): array
    {
        $rows = [];
        $misses = [];

        foreach ($questions as $question) {
            $hits = $retriever->retrieve($question['q'], self::DEPTH);
            $rank = $this->firstRelevant($hits, $question['expect']);

            $rows[] = ['kind' => $question['kind'], 'rank' => $rank];

            if ($rank === null || $rank > $k) {
                $misses[] = [
                    'kind' => $question['kind'],
                    'q'    => $question['q'],
                    'top'  => $hits === [] ? '(nothing)' : $hits[0]->chunk->source(),
                ];
            }
        }

        $kinds = [];
        foreach (array_unique(array_column($rows, 'kind')) as $kind) {
            $kinds[$kind] = self::score(array_values(array_filter($rows, static fn(array $r) => $r['kind'] === $kind)), $k);
        }

        return ['overall' => self::score($rows, $k), 'kinds' => $kinds, 'misses' => $misses];
    }

    /**
     * Rank of the first hit in an expected section; several chunks of one
     * section count once, at the best of them.
     *
     * @param list<Hit>                                  $hits
     * @param list<array{path: string, section: string}> $expected
     */
    private function firstRelevant(array $hits, array $expected): ?int
    {
        foreach ($hits as $index => $hit) {
            $headings = implode(' › ', $hit->chunk->headings);

            foreach ($expected as $target) {
                if ($hit->chunk->path === $target['path'] && ($target['section'] === '' || str_contains($headings, $target['section']))) {
                    return $index + 1;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{kind: string, rank: int|null}> $rows
     *
     * @return array{n: int, r1: float, rk: float, mrr: float}
     */
    private static function score(array $rows, int $k): array
    {
        $n = count($rows);
        if ($n === 0) {
            return ['n' => 0, 'r1' => 0.0, 'rk' => 0.0, 'mrr' => 0.0];
        }

        $r1 = $rk = $mrr = 0.0;
        foreach ($rows as $row) {
            $rank = $row['rank'];
            if ($rank === null) {
                continue;
            }
            $r1 += $rank === 1 ? 1 : 0;
            $rk += $rank <= $k ? 1 : 0;
            $mrr += 1 / $rank;
        }

        return ['n' => $n, 'r1' => $r1 / $n, 'rk' => $rk / $n, 'mrr' => $mrr / $n];
    }

    /**
     * The results side by side, one line per strategy per kind.
     *
     * @param array<string, array{overall: array, kinds: array}> $results strategy name => run()
     */
    public static function table(array $results, int $k = 5): string
    {
        $lines = [sprintf('  %-16s %-11s %4s  %9s  %9s  %6s', 'strategy', 'questions', 'n', 'recall@1', "recall@{$k}", 'MRR')];

        foreach ($results as $name => $result) {
            foreach (['tout' => $result['overall']] + $result['kinds'] as $kind => $s) {
                $lines[] = sprintf('  %-16s %-11s %4d  %8.0f%%  %8.0f%%  %6.2f', $name, $kind, $s['n'], $s['r1'] * 100, $s['rk'] * 100, $s['mrr']);
            }
        }

        return implode("\n", $lines);
    }
}
