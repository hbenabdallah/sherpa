<?php

namespace Boutique\Routing;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Route
{
    public function __construct(
        public readonly string $path,
        public readonly string $method = 'GET',
    ) {}
}
