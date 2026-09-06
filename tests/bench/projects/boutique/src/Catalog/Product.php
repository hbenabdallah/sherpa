<?php

namespace Boutique\Catalog;

final class Product
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $priceCents,
    ) {}
}
