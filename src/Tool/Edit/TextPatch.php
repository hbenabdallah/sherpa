<?php

declare(strict_types=1);

namespace App\Tool\Edit;

/**
 * One search-and-replace, applied the way a model means it rather than only the
 * way it typed it. The benchmark had file_patch failing on about one call in
 * two, at a whole turn each, almost always on the same few slips: backslashes
 * escaped twice (`Boutique\\User`), Windows line endings on one side only, the
 * right lines at the wrong indentation.
 *
 * Each fallback runs only after the exact match failed, and only when it
 * matches one place — a correction that could land in two is a guess. When
 * nothing matches, the error quotes the closest text in the file.
 */
final class TextPatch
{
    /** Below this, the "closest line" is no longer close enough to be worth quoting. */
    private const HINT_SIMILARITY = 50.0;

    /** Lines quoted around the closest match. */
    private const HINT_LINES = 6;

    public static function apply(string $content, string $search, string $replace): PatchOutcome
    {
        if ($search === '') {
            return PatchOutcome::failed('search string is empty');
        }

        $exact = self::unique($content, $search, $replace, 'exact');
        if ($exact !== null) {
            return $exact;
        }

        // A file with CRLF line endings, searched with plain newlines.
        if (str_contains($content, "\r\n") && !str_contains($search, "\r")) {
            $crlf = self::unique(
                $content,
                str_replace("\n", "\r\n", $search),
                str_replace("\n", "\r\n", $replace),
                'line endings',
            );
            if ($crlf !== null) {
                return $crlf;
            }
        }

        // Escaped twice. The replacement carries the same habit, so it is
        // collapsed too: writing `Boutique\\User` into a PHP file would trade a
        // failed patch for a broken file.
        if (str_contains($search, '\\\\')) {
            $collapsed = self::unique(
                $content,
                str_replace('\\\\', '\\', $search),
                str_replace('\\\\', '\\', $replace),
                'doubled backslashes',
            );
            if ($collapsed !== null) {
                return $collapsed;
            }
        }

        $indented = self::ignoringIndentation($content, $search, $replace);
        if ($indented !== null) {
            return $indented;
        }

        return self::notFound($content, $search);
    }

    /**
     * Replace $search when it occurs exactly once; report it when it occurs
     * more than once; null when it does not occur, so the next fallback runs.
     */
    private static function unique(string $content, string $search, string $replace, string $how): ?PatchOutcome
    {
        $count = substr_count($content, $search);

        if ($count === 0) {
            return null;
        }

        if ($count > 1) {
            return PatchOutcome::failed(
                "search string appears {$count} times (lines " . implode(', ', self::linesOf($content, $search))
                . ') — include more of the surrounding code so it matches exactly one place',
            );
        }

        $offset = strpos($content, $search);

        return new PatchOutcome(
            content: substr_replace($content, $replace, $offset, strlen($search)),
            how: $how,
            line: substr_count($content, "\n", 0, $offset) + 1,
            matched: $search,
            replacement: $replace,
        );
    }

    /**
     * The same lines, compared without their leading and trailing whitespace.
     *
     * Lines of the replacement that repeat a line of the search keep the
     * file's own version of it, indentation included; lines that are new are
     * shifted by however far the search's first line was off.
     */
    private static function ignoringIndentation(string $content, string $search, string $replace): ?PatchOutcome
    {
        $searchLines = explode("\n", rtrim($search, "\n"));
        if (array_filter($searchLines, fn($l) => trim($l) !== '') === []) {
            return null;
        }

        $lines = explode("\n", $content);
        $wanted = array_map('trim', $searchLines);
        $n = count($wanted);
        $starts = [];

        for ($i = 0; $i + $n <= count($lines); $i++) {
            for ($k = 0; $k < $n; $k++) {
                if (trim($lines[$i + $k]) !== $wanted[$k]) {
                    continue 2;
                }
            }
            $starts[] = $i;
        }

        if ($starts === []) {
            return null;
        }

        if (count($starts) > 1) {
            return PatchOutcome::failed(
                'search string matches ' . count($starts) . ' places once indentation is ignored (lines '
                . implode(', ', array_map(fn($s) => $s + 1, $starts)) . ') — include more of the surrounding code',
            );
        }

        $start = $starts[0];
        $first = array_key_first(array_filter($searchLines, fn($l) => trim($l) !== ''));
        $from = self::indentOf($searchLines[$first]);
        $to = self::indentOf($lines[$start + $first]);

        $replacement = [];
        foreach (explode("\n", rtrim($replace, "\n")) as $k => $line) {
            if ($k < $n && trim($line) === $wanted[$k]) {
                $replacement[] = $lines[$start + $k];
            } elseif (trim($line) === '') {
                $replacement[] = '';
            } elseif ($from !== '' && str_starts_with($line, $from)) {
                $replacement[] = $to . substr($line, strlen($from));
            } else {
                $replacement[] = $to . $line;
            }
        }

        $matched = array_slice($lines, $start, $n);
        array_splice($lines, $start, $n, $replacement);

        return new PatchOutcome(
            content: implode("\n", $lines),
            how: 'indentation',
            line: $start + 1,
            matched: implode("\n", $matched),
            replacement: implode("\n", $replacement),
        );
    }

    /**
     * Not found anywhere: quote the closest text there is, so the model copies
     * it instead of trying a sixth variation of what it remembers.
     */
    private static function notFound(string $content, string $search): PatchOutcome
    {
        $needle = '';
        foreach (explode("\n", $search) as $line) {
            if (trim($line) !== '') {
                $needle = trim($line);
                break;
            }
        }

        $lines = explode("\n", $content);
        $best = -1;
        $bestScore = 0.0;

        foreach ($lines as $i => $line) {
            $candidate = trim($line);
            if ($candidate === '') {
                continue;
            }

            similar_text($needle, $candidate, $score);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $i;
            }
        }

        if ($best < 0 || $bestScore < self::HINT_SIMILARITY) {
            return PatchOutcome::failed(
                'search string not found, and no line of the file resembles it — read the file again before patching',
            );
        }

        $span = min(self::HINT_LINES, max(1, substr_count(rtrim($search, "\n"), "\n") + 1));
        $quote = implode("\n", array_slice($lines, $best, $span));
        $range = $span === 1 ? 'line ' . ($best + 1) : 'lines ' . ($best + 1) . '-' . ($best + $span);

        return PatchOutcome::failed(
            'search string not found',
            "The closest text in the file ({$range}) is:\n{$quote}\n"
                . 'Copy it exactly into search — spaces, quotes and backslashes included.',
        );
    }

    /** @return int[] 1-based lines where $search starts */
    private static function linesOf(string $content, string $search): array
    {
        $lines = [];
        $offset = 0;

        while (($found = strpos($content, $search, $offset)) !== false) {
            $lines[] = substr_count($content, "\n", 0, $found) + 1;
            $offset = $found + 1;
        }

        return $lines;
    }

    private static function indentOf(string $line): string
    {
        return substr($line, 0, strlen($line) - strlen(ltrim($line, " \t")));
    }
}
