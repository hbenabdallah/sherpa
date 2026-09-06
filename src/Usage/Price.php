<?php

declare(strict_types=1);

namespace App\Usage;

/**
 * What one model costs, per million tokens, as its provider bills it.
 *
 * Declared by the user in config.yaml, never shipped: prices change by the
 * month and differ by provider, plan and currency, and a number Sherpa made up
 * would be a wrong number presented as a fact.
 */
final class Price
{
    public function __construct(
        public readonly float $input,
        public readonly float $output,
        /** Prompt tokens served from the provider's cache; billed as input when null. */
        public readonly ?float $cachedInput = null,
        public readonly string $currency = '$',
    ) {}

    public function of(int $prompt, int $completion, int $cached = 0): float
    {
        $cached = max(0, min($cached, $prompt));

        return (($prompt - $cached) * $this->input
            + $cached * ($this->cachedInput ?? $this->input)
            + $completion * $this->output) / 1_000_000;
    }
}
