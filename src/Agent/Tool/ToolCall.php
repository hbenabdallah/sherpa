<?php

declare(strict_types=1);

namespace App\Agent\Tool;

final readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}
}
