<?php

namespace App\Agent;

final readonly class Result
{
    public function __construct(
        public string $content,
        public int $iterations = 0,
        public bool $maxIterationsReached = false,
        public bool $interrupted = false,
        public bool $repetitionDetected = false,
        // Stopped for repetition, but the model had replied in the meantime:
        // $content is that reply, not the stop notice.
        public bool $repliedBeforeStop = false,
        // Stopped because every call came back with this same error: what is
        // in the way is the user's to fix, and they are shown it.
        public ?string $repeatedError = null,
    ) {}
}
