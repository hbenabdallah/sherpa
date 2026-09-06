<?php

namespace App\Mcp;

use Psr\Log\AbstractLogger;

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
    /** How much of stderr to keep for the error message. */
    private const KEEP = 4000;

    private string $stderr = '';

    /**
     * @param mixed[] $context
     */
    public function log(mixed $level, \Stringable|string $message, array $context = []): void
    {
        // The SDK's own wording for a drained stderr chunk. Matching on it is
        // brittle by nature, which is why nothing depends on it: a version that
        // renames the message costs a less detailed error, not a broken client.
        if ((string) $message === 'Server stderr' && isset($context['output'])) {
            $this->stderr = substr($this->stderr . (string) $context['output'] . "\n", -self::KEEP);
        }
    }

    /** The server's own words, or an empty string when it said nothing. */
    public function stderr(): string
    {
        return trim($this->stderr);
    }
}
