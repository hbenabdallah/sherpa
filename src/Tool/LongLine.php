<?php

namespace App\Tool;

/**
 * A line too long to be read: minified JSON, a bundled script, a lock file.
 *
 * One such line can weigh a megabyte, and sent whole it fills the model's
 * window on its own: the next request is refused for exceeding the window, and
 * the turn is lost. Seen in a real session, from one grep that matched a JSON
 * test fixture. What is kept is a window of the line — around the match when
 * there is one, else its start — and how long the line really was.
 */
final class LongLine
{
    public static function clip(string $line, int $max, ?string $pattern = null): string
    {
        if (strlen($line) <= $max) {
            return $line;
        }

        // grep -E and PCRE agree on what models write; where they do not, the
        // start of the line is shown instead of the match — still bounded.
        $at = 0;
        if ($pattern !== null && @preg_match('~' . str_replace('~', '\~', $pattern) . '~u', $line, $match, PREG_OFFSET_CAPTURE) === 1) {
            $at = $match[0][1];
        }

        $start = max(0, $at - intdiv($max, 3));
        $slice = mb_scrub(substr($line, $start, $max), 'UTF-8');

        return ($start > 0 ? '…' : '') . $slice
            . sprintf('… [line of %s characters, cut]', number_format(mb_strlen($line), 0, '.', ','));
    }
}
