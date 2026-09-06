<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * A command typed as one line — `sh -c 'exec docker run …'` — cut into the
 * words a process is started with, the way a POSIX shell would cut it.
 *
 * Only the quoting: no variable is expanded, no glob, no pipe. The words go
 * to proc_open as they are, so what the user typed is what runs; a command
 * that needs a shell says so itself with `sh -c`, as in mcp.json.
 */
final class CommandLine
{
    /**
     * @return array<int, string>
     *
     * @throws McpException on a quote left open, which would otherwise swallow
     *                      the rest of the line without a word
     */
    public static function split(string $line): array
    {
        $words = [];
        $word = '';
        $inWord = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === ' ' || $char === "\t" || $char === "\n") {
                if ($inWord) {
                    $words[] = $word;
                    $word = '';
                    $inWord = false;
                }
                continue;
            }

            $inWord = true;

            // Single quotes: everything up to the next one, as it is.
            if ($char === "'") {
                $end = strpos($line, "'", $i + 1);
                if ($end === false) {
                    throw new McpException('A single quote is left open in: ' . $line);
                }
                $word .= substr($line, $i + 1, $end - $i - 1);
                $i = $end;
                continue;
            }

            // Double quotes: a backslash escapes only what it escapes in sh.
            if ($char === '"') {
                for ($i++; $i < $length && $line[$i] !== '"'; $i++) {
                    if ($line[$i] === '\\' && $i + 1 < $length && str_contains('"\\$`', $line[$i + 1])) {
                        $i++;
                    }
                    $word .= $line[$i];
                }
                if ($i >= $length) {
                    throw new McpException('A double quote is left open in: ' . $line);
                }
                continue;
            }

            if ($char === '\\' && $i + 1 < $length) {
                $word .= $line[++$i];
                continue;
            }

            $word .= $char;
        }

        if ($inWord) {
            $words[] = $word;
        }

        return $words;
    }
}
