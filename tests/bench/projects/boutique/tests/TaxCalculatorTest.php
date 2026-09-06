<?php

use Boutique\Billing\TaxCalculator;

function test_vat_depends_on_country(): void
{
    $taxes = new TaxCalculator();

    assert_same(200, $taxes->vatFor(1000, 'FR'));
    assert_same(190, $taxes->vatFor(1000, 'de'));
}

function test_unknown_country_falls_back_to_france(): void
{
    assert_same(200, (new TaxCalculator())->vatFor(1000, 'ZZ'));
}
