<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Param
{
    public function __construct(
        public string $description,
    ) {}
}
