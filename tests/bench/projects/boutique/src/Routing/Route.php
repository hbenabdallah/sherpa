<?php

namespace Boutique\Routing;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Route
{
    public function __construct(
        public string $path,
        public string $method = 'GET',
    ) {}
}
