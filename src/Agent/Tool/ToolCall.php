<?php

declare(strict_types=1);

namespace App\Agent\Tool;

final readonly class ToolCall
{
    /** @param array<string, mixed> $arguments as the model wrote them */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}
}
