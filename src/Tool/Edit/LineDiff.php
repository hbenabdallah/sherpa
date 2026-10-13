<?php

declare(strict_types=1);

namespace App\Tool\Edit;

/**
 * What an edit does to a file, as the confirmation shows it: the lines that
 * change, a few around them, and where they are — what `git diff` would show.
 *
 * Before, file_patch showed the whole search block removed and the whole
 * replacement added, and file_write, from the first difference on, the rest
 * of the old file removed then the rest of the new one added: twenty red and
 * green lines for one changed character, the change itself left to find. That
 * screen is the one the user approves an edit from, so it has to say what the
 * edit does, not what the model sent.
 */
final class LineDiff
{
    /** Unchanged lines shown around each change. */
    private const CONTEXT = 3;

    /**
     * Past this many line pairs in the changed part, its lines are not
     * aligned: it shows as all removed, then all added. A rewrite, not an
     * edit, and the alignment's table would outgrow the memory.
     */
    private const ALIGN_LIMIT = 250_000;

    public static function unified(string $old, string $new, string $label): string
    {
        $header = ["--- a/{$label}", "+++ b/{$label}"];
        if ($old === $new) {
            return implode("\n", [...$header, '(no change)']);
        }

        $a = explode("\n", $old);
        $b = explode("\n", $new);
        // A final newline on both sides is not a line of either.
        if (end($a) === '' && end($b) === '') {
            array_pop($a);
            array_pop($b);
        }

        return implode("\n", [...$header, ...self::hunks(self::ops($a, $b))]);
    }

    /**
     * Each line of either side in order: [' ' | '-' | '+', text].
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{string, string}>
     */
    private static function ops(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        $start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) {
            $start++;
        }
        $end = 0;
        while ($end < $n - $start && $end < $m - $start && $a[$n - 1 - $end] === $b[$m - 1 - $end]) {
            $end++;
        }

        $ops = array_map(fn(string $l) => [' ', $l], array_slice($a, 0, $start));
        $oldMid = array_slice($a, $start, $n - $start - $end);
        $newMid = array_slice($b, $start, $m - $start - $end);

        $ops = count($oldMid) * count($newMid) <= self::ALIGN_LIMIT
            ? [...$ops, ...self::aligned($oldMid, $newMid)]
            : [...$ops, ...array_map(fn(string $l) => ['-', $l], $oldMid), ...array_map(fn(string $l) => ['+', $l], $newMid)];

        return [...$ops, ...array_map(fn(string $l) => [' ', $l], array_slice($a, $n - $end))];
    }

    /**
     * The longest run of lines both sides share, kept; the rest removed or
     * added, removals first, as git shows them.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{string, string}>
     */
    private static function aligned(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        // $common[$i][$j]: lines shared by what follows $a[$i] and $b[$j], included.
        $common = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $common[$i][$j] = $a[$i] === $b[$j]
                    ? $common[$i + 1][$j + 1] + 1
                    : max($common[$i + 1][$j], $common[$i][$j + 1]);
            }
        }

        $ops = [];
        [$i, $j] = [0, 0];
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $ops[] = [' ', $a[$i++]];
                $j++;
            } elseif ($j >= $m || $i < $n && $common[$i + 1][$j] >= $common[$i][$j + 1]) {
                $ops[] = ['-', $a[$i++]];
            } else {
                $ops[] = ['+', $b[$j++]];
            }
        }

        return $ops;
    }

    /**
     * The changes in hunks, each with its context and its place in both
     * files; changes closer than twice the context share one.
     *
     * @param list<array{string, string}> $ops
     * @return list<string>
     */
    private static function hunks(array $ops): array
    {
        $changed = array_keys(array_filter($ops, fn(array $op) => $op[0] !== ' '));

        $groups = [];
        foreach ($changed as $index) {
            $last = array_key_last($groups);
            if ($last !== null && $index - $groups[$last][1] <= 2 * self::CONTEXT + 1) {
                $groups[$last][1] = $index;
            } else {
                $groups[] = [$index, $index];
            }
        }

        // Where each op stands in the old file and the new, 1-based.
        $positions = [];
        [$old, $new] = [1, 1];
        foreach ($ops as $index => [$kind]) {
            $positions[$index] = [$old, $new];
            $old += $kind !== '+' ? 1 : 0;
            $new += $kind !== '-' ? 1 : 0;
        }

        $lines = [];
        foreach ($groups as [$first, $last]) {
            $from = max(0, $first - self::CONTEXT);
            $to = min(count($ops) - 1, $last + self::CONTEXT);
            $slice = array_slice($ops, $from, $to - $from + 1);

            $oldCount = count(array_filter($slice, fn(array $op) => $op[0] !== '+'));
            $newCount = count(array_filter($slice, fn(array $op) => $op[0] !== '-'));
            [$oldStart, $newStart] = $positions[$from];

            $lines[] = sprintf('@@ -%s +%s @@', self::range($oldStart, $oldCount), self::range($newStart, $newCount));
            foreach ($slice as [$kind, $text]) {
                $lines[] = "{$kind} {$text}";
            }
        }

        return $lines;
    }

    /** A hunk's range as unified diffs write it: an empty one sits before its line. */
    private static function range(int $start, int $count): string
    {
        return $count === 0 ? ($start - 1) . ',0' : ($count === 1 ? "{$start}" : "{$start},{$count}");
    }
}
