<?php

declare(strict_types=1);

namespace App\Usage;

/**
 * Thrown before a request that the session's token cap no longer allows.
 *
 * Before, never after: the point of a cap is that the request which would
 * cross it is not sent — nothing is billed, nothing is half-done.
 */
final class TokenCapReached extends \RuntimeException
{
}
