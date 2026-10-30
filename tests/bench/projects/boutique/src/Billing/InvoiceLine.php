<?php

declare(strict_types=1);

namespace Boutique\Billing;

use Boutique\Catalog\Product;

final readonly class InvoiceLine
{
    public function __construct(
        public Product $product,
        public int $quantity,
    ) {}
}
