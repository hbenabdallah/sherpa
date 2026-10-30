<?php

namespace App\Agent\Tool;

final readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}
}
