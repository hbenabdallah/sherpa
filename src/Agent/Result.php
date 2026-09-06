<?php

namespace App\Agent;

final class Result
{
    public function __construct(
        public readonly string $content,
        public readonly int $iterations = 0,
        public readonly bool $maxIterationsReached = false,
        public readonly bool $interrupted = false,
    ) {}
}