<?php

namespace App\Agent\Tool;

final readonly class ToolResult
{
    public function __construct(
        public string $callId,
        public string $content,
        public bool $isError = false,
    ) {}
}
