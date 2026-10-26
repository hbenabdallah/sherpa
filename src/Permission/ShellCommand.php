<?php

namespace App\Permission;

/**
 * A command line as the shell will split it: the simple commands it runs, in
 * order, as words. Enough of the shell's grammar to know what runs — quotes,
 * escapes, comments, the operators that chain commands and redirections — and
 * no more: whatever decides at run time what runs (a substitution, a loop, a
 * here-document) is not interpreted, it makes the command opaque, and an
 * opaque command is only ever allowed once, by someone who has read it.
 */
final class ShellCommand
{
    /** Words that open a compound command: what it runs depends on what it computes. */
    private const KEYWORDS = ['if', 'then', 'else', 'elif', 'fi', 'for', 'while', 'until', 'do', 'done', 'case', 'esac', 'select', 'function'];

    /** What may be written to without writing a file. */
    private const SINKS = ['/dev/null', '/dev/stdout', '/dev/stderr'];

    /**
     * @param list<list<string>> $segments the simple commands, as words
     * @param list<bool>         $piped    whether each one reads the output of the one before
     * @param string|null        $opaque   why no grant can cover it, or null
     */
    private function __construct(
        public readonly array $segments,
        public readonly array $piped,
        public readonly ?string $opaque,
    ) {}

    public static function parse(string $line): self
    {
        $segments = [];
        $piped = [];
        $opaque = null;

        $words = [];
        $word = '';
        $inWord = false;
        $nextPiped = false;
        // The operator of a redirection waiting for its target, the next word.
        $redirect = null;

        $endWord = function () use (&$word, &$inWord, &$words, &$redirect, &$opaque): void {
            if (!$inWord) {
                return;
            }
            if ($redirect !== null) {
                $opaque ??= self::redirection($redirect, $word);
                $redirect = null;
            } else {
                $words[] = $word;
            }
            $word = '';
            $inWord = false;
        };
        $endSegment = function (bool $pipe) use (&$words, &$segments, &$piped, &$nextPiped, $endWord): void {
            $endWord();
            // Grouping braces and negation change nothing about what runs.
            while ($words !== [] && in_array($words[0], ['{', '}', '!'], true)) {
                array_shift($words);
            }
            while ($words !== [] && $words[count($words) - 1] === '}') {
                array_pop($words);
            }
            if ($words !== []) {
                $segments[] = $words;
                $piped[] = $nextPiped;
            }
            $words = [];
            $nextPiped = $pipe;
        };

        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $c = $line[$i];
            $next = $line[$i + 1] ?? '';

            if ($c === '\\') {
                // A line continued on the next is one line.
                if ($next === "\n") {
                    $i++;
                    continue;
                }
                $word .= $next;
                $inWord = true;
                $i++;
                continue;
            }

            if ($c === "'") {
                $end = strpos($line, "'", $i + 1);
                if ($end === false) {
                    $opaque ??= 'a quote is never closed';
                    $end = $length;
                }
                $word .= substr($line, $i + 1, $end - $i - 1);
                $inWord = true;
                $i = $end;
                continue;
            }

            if ($c === '"') {
                $inWord = true;
                for ($i++; $i < $length && $line[$i] !== '"'; $i++) {
                    if ($line[$i] === '\\' && in_array($line[$i + 1] ?? '', ['"', '\\', '$', '`', "\n"], true)) {
                        $word .= $line[++$i];
                        continue;
                    }
                    if ($line[$i] === '`' || ($line[$i] === '$' && ($line[$i + 1] ?? '') === '(')) {
                        $opaque ??= 'part of it is the output of another command';
                    }
                    $word .= $line[$i];
                }
                if ($i >= $length) {
                    $opaque ??= 'a quote is never closed';
                }
                continue;
            }

            if ($c === '`' || ($c === '$' && $next === '(')) {
                $opaque ??= 'part of it is the output of another command';
            }

            if (($c === '<' || $c === '>') && $next === '(') {
                $opaque ??= 'part of it is the output of another command';
                $i++;
                continue;
            }

            // A comment runs to the end of the line, operators included.
            if ($c === '#' && !$inWord) {
                $end = strpos($line, "\n", $i);
                $i = ($end === false ? $length : $end) - 1;
                continue;
            }

            if ($c === ' ' || $c === "\t") {
                $endWord();
                continue;
            }

            if ($c === "\n" || $c === ';' || $c === '(' || $c === ')') {
                $endSegment(false);
                if ($c === ';' && $next === ';') {
                    $i++;
                }
                continue;
            }

            if ($c === '|') {
                // || runs the next one after the first, | feeds it: |& feeds both streams.
                if ($next === '|') {
                    $endSegment(false);
                    $i++;
                } else {
                    $endSegment(true);
                    if ($next === '&') {
                        $i++;
                    }
                }
                continue;
            }

            if ($c === '&' && $next !== '>') {
                $endSegment(false);
                if ($next === '&') {
                    $i++;
                }
                continue;
            }

            if ($c === '<' || $c === '>' || $c === '&') {
                // A file descriptor written before it: 2>/dev/null.
                if ($inWord && ctype_digit($word)) {
                    $word = '';
                    $inWord = false;
                }
                $endWord();
                $operator = $c;
                while (in_array($line[$i + 1] ?? '', ['<', '>', '&', '|'], true) && strlen($operator) < 3) {
                    $operator .= $line[++$i];
                }
                if (str_starts_with($operator, '<<') && $operator !== '<<<') {
                    $opaque ??= 'it holds a here-document';
                }
                $redirect = $operator;
                continue;
            }

            $word .= $c;
            $inWord = true;
        }

        $endSegment(false);

        if ($redirect !== null) {
            $opaque ??= 'a redirection has no target';
        }

        foreach ($segments as $segment) {
            if (in_array($segment[0], self::KEYWORDS, true)) {
                $opaque ??= 'it is a loop or a condition, which decides at run time what runs';
            }
        }

        return new self($segments, $piped, $opaque);
    }

    /** Why a redirection makes the command opaque, or null when it writes no file. */
    private static function redirection(string $operator, string $target): ?string
    {
        // Input, and one stream sent to another: nothing is written.
        if ($operator === '<' || $operator === '<<<') {
            return null;
        }
        if (in_array($operator, ['>&', '<&'], true) && (ctype_digit($target) || $target === '-')) {
            return null;
        }
        if (in_array($target, self::SINKS, true)) {
            return null;
        }

        return "it writes to {$target}";
    }

    /** A word as the shell would read it back: quoted only when it has to be. */
    public static function quote(string $word): string
    {
        if ($word !== '' && preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#', $word) === 1) {
            return $word;
        }

        return "'" . str_replace("'", "'\\''", $word) . "'";
    }
}
