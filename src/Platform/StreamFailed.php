<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * The server gave up after the stream opened. Overloads say so this way on
 * some providers, and are worth a retry — as long as nothing reached the
 * screen yet, since a second reply would print after the first half.
 */
final class StreamFailed extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }

    /** What reads as a passing condition: an overload, a rate, a timeout. */
    public static function from(string $message, mixed $code = null): self
    {
        $transient = in_array((int) $code, [429, 500, 502, 503, 504, 529], true)
            || preg_match('/overload|temporar|rate.?limit|try again|timed? ?out|unavailable/i', $message) === 1;

        return new self($message, $transient);
    }
}
