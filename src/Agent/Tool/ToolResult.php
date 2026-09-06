<?php

namespace App\Agent\Tool;

final class ToolResult
{
    public function __construct(
        public readonly string $callId,
        public readonly string $content,
        public readonly bool $isError = false,
    ) {}
}