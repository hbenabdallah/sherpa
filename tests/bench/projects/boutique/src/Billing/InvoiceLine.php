<?php

namespace Boutique\Billing;

use Boutique\Catalog\Product;

final class InvoiceLine
{
    public function __construct(
        public readonly Product $product,
        public readonly int $quantity,
    ) {}
}
