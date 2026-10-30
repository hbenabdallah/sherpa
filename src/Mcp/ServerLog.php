<?php

declare(strict_types=1);

namespace App\Mcp;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * What a server said about itself while it was failing.
 *
 * The SDK drains the child process's stderr — it has to, a full pipe buffer
 * blocks the very process we are waiting on — but it only writes it to a
 * logger, at debug level. That output is the single most useful thing there is
 * when a server will not start: "fatal: missing configuration" is an answer,
 * "Initialization failed" is not.
 *
 * So Sherpa passes this in and keeps the tail of it, to quote in the message
 * the user actually reads. Only the tail: a server that logs a megabyte before
 * dying is not owed a megabyte of our memory.
 */
final class ServerLog extends AbstractLogger
{
    /** How much of stderr to keep, and to quote: the end, where the reason is. */
    private const int KEEP = 4000;

    private const int QUOTED_LINES = 15;

    private const int QUOTED_CHARS = 1500;

    private string $stderr = '';

    /**
     * @param mixed[] $context
     */
    public function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        // The SDK's own wording for a drained stderr chunk. Matching on it is
        // brittle by nature, which is why nothing depends on it: a version that
        // renames the message costs a less detailed error, not a broken client.
        if ((string) $message === 'Server stderr' && isset($context['output'])) {
            $this->stderr = substr($this->stderr . $context['output'] . "\n", -self::KEEP);
        }
    }

    /**
     * The server's last words, or an empty string when it said nothing. A
     * screenful at most: a stack trace of four thousand characters buried the
     * one line that said what was wrong.
     */
    public function stderr(): string
    {
        $lines = explode("\n", trim($this->stderr));
        $tail = trim(implode("\n", array_slice($lines, -self::QUOTED_LINES)));
        if (strlen($tail) > self::QUOTED_CHARS) {
            $tail = '…' . mb_strcut($tail, -self::QUOTED_CHARS);
        }

        return count($lines) > self::QUOTED_LINES || str_starts_with($tail, '…')
            ? "(the end of what it printed)\n" . $tail
            : $tail;
    }
}
