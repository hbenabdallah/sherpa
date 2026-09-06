<?php

declare(strict_types=1);

namespace App\TUI\Input;

/**
 * Which part of a line fits after the prompt, and where the cursor lands in it.
 *
 * A line longer than the terminal is wide either wraps or scrolls. Wrapping
 * means tracking how many rows the line spans to redraw them all, and getting
 * it wrong by one leaves stale text on screen; a window that slides with the
 * cursor cannot go wrong that way. Widths are counted in terminal cells, not
 * characters: a CJK character takes two, an accent combining onto the letter
 * before it takes none.
 */
final class LineView
{
    /**
     * @param list<string> $chars
     *
     * @return array{0: string, 1: int} what to print, and the cursor's column
     *                                  within it
     */
    public static function window(array $chars, int $cursor, int $available): array
    {
        $available = max(1, $available);
        $widths = array_map(static fn(string $c) => mb_strwidth($c), $chars);

        // Slide the start right until the cursor, plus a cell to stand on, fits.
        $start = 0;
        while ($start < $cursor && array_sum(array_slice($widths, $start, $cursor - $start)) + 1 > $available) {
            $start++;
        }

        $shown = '';
        $used = 0;
        for ($i = $start, $n = count($chars); $i < $n; $i++) {
            if ($used + $widths[$i] > $available) {
                break;
            }
            $shown .= $chars[$i];
            $used += $widths[$i];
        }

        return [$shown, (int) array_sum(array_slice($widths, $start, $cursor - $start))];
    }
}
