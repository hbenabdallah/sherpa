<?php

namespace Boutique\Billing;

final class Invoice
{
    /** @var InvoiceLine[] */
    private array $lines = [];

    public function __construct(
        public readonly string $country,
        private readonly TaxCalculator $taxes = new TaxCalculator(),
    ) {}

    public function add(InvoiceLine $line): void
    {
        $this->lines[] = $line;
    }

    public function subtotal(): int
    {
        $sum = 0;
        foreach ($this->lines as $line) {
            $sum += $line->product->priceCents * $line->quantity;
        }

        return $sum;
    }

    public function total(): int
    {
        $subtotal = $this->subtotal();

        return $subtotal + $this->taxes->vatFor($subtotal, $this->country);
    }
}
