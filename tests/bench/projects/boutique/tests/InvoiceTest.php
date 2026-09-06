<?php

use Boutique\Billing\Invoice;
use Boutique\Billing\InvoiceLine;
use Boutique\Catalog\Product;

function test_invoice_subtotal_counts_quantities(): void
{
    $invoice = new Invoice('FR');
    $invoice->add(new InvoiceLine(new Product(1, 'Carnet', 450), 3));
    $invoice->add(new InvoiceLine(new Product(2, 'Stylo', 1000), 1));

    assert_same(2350, $invoice->subtotal());
}

function test_invoice_total_adds_french_vat(): void
{
    $invoice = new Invoice('FR');
    $invoice->add(new InvoiceLine(new Product(1, 'Carnet', 1000), 2));

    assert_same(2400, $invoice->total());
}
