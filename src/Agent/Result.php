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
    ) {}
}