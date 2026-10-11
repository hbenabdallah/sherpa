<?php

namespace App\Agent;

final class Result
{
    public function __construct(
        public readonly string $content,
        public readonly int $iterations = 0,
        public readonly bool $maxIterationsReached = false,
        public readonly bool $interrupted = false,
        public readonly bool $repetitionDetected = false,
        // Stopped for repetition, but the model had replied in the meantime:
        // $content is that reply, not the stop notice.
        public readonly bool $repliedBeforeStop = false,
        // Stopped because every call came back with this same error: what is
        // in the way is the user's to fix, and they are shown it.
        public readonly ?string $repeatedError = null,
    ) {}
}