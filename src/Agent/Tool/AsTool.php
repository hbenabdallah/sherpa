<?php

declare(strict_types=1);

namespace App\Agent\Tool;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsTool
{
    public function __construct(
        public string     $name,
        public string     $description,
        public Permission $permission = Permission::AUTO,
    ) {}
}