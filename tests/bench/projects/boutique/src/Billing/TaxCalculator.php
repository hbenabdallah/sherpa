<?php

namespace Boutique\Billing;

final class TaxCalculator
{
    /** Taux de TVA par pays, en pourcentage. */
    private const RATES = ['FR' => 20, 'DE' => 19, 'ES' => 21, 'LU' => 17];

    public function vatFor(int $amountCents, string $country): int
    {
        $rate = self::RATES[strtoupper($country)] ?? self::RATES['FR'];

        return (int) round($amountCents * $rate / 100);
    }
}
