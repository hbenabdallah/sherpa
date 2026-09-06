<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * What one turn of AgentLoop remembers from one request to the next: whether
 * the model is circling, what it said while doing so, and which second
 * chances it has already had.
 */
final class TurnState
{
    /** The last batch of calls, to notice the same one made again. */
    public ?string $lastFingerprint = null;

    /** How many times in a row that batch has been made. */
    public int $repeats = 0;

    /** Text written alongside the repeated calls — an answer, often. */
    public string $saidWhileRepeating = '';

    /** An empty reply has already been asked again. */
    public bool $retriedEmpty = false;

    /** An announced call has already been asked for. */
    public bool $nudged = false;

    /**
     * Why the model has been asked to answer now, without tools — the token
     * budget or the turn's steps running out. Null while it is still working.
     */
    public ?string $closing = null;

    /** Tool calls made after that request, and not run. */
    public int $refusedWhileClosing = 0;
}
