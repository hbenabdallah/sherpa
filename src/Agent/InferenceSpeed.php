<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * How fast this machine actually generates, measured rather than configured,
 * from the durations every reply reports. The speed half of what ContextBudget
 * does for size: a machine without a GPU is the slow end of a scale, not a
 * special case.
 */
final class InferenceSpeed
{
    /**
     * Matches ContextBudget's calibration weight, for the same reason: one turn
     * is noisy. A short prompt against a warm cache reads as absurdly fast, a
     * cold load as absurdly slow, and neither describes the machine.
     */
    private const SMOOTHING = 0.3;

    /** Tokens per second, prompt-evaluation (prefill) and generation (decode). */
    private ?float $prefill = null;
    private ?float $decode = null;

    private int $samples = 0;

    /** Which model the figures below describe; see useModel(). */
    private ?string $model = null;

    public function observe(float $prefillTokensPerSecond, float $decodeTokensPerSecond): void
    {
        $seen = false;

        // Implausible figures are dropped rather than smoothed in: a request
        // served entirely from the prefix cache reports a prefill of a handful
        // of tokens in microseconds, which is true and tells us nothing about
        // the machine.
        if ($prefillTokensPerSecond > 0.0 && $prefillTokensPerSecond < 1_000_000.0) {
            $this->prefill = $this->fold($this->prefill, $prefillTokensPerSecond);
            $seen = true;
        }
        if ($decodeTokensPerSecond > 0.0 && $decodeTokensPerSecond < 100_000.0) {
            $this->decode = $this->fold($this->decode, $decodeTokensPerSecond);
            $seen = true;
        }

        if ($seen) {
            $this->samples++;
        }
    }

    private function fold(?float $current, float $observed): float
    {
        if ($current === null) {
            return $observed;
        }

        return ($current * (1 - self::SMOOTHING)) + ($observed * self::SMOOTHING);
    }

    public function isMeasured(): bool
    {
        return $this->samples > 0;
    }

    public function prefillTokensPerSecond(): ?float
    {
        return $this->prefill;
    }

    public function decodeTokensPerSecond(): ?float
    {
        return $this->decode;
    }

    /**
     * Estimated seconds to re-read a prompt of this size — the question every
     * caller has, since compaction costs a full re-read on the next request.
     */
    public function secondsToPrefill(int $tokens): ?float
    {
        if ($this->prefill === null || $this->prefill <= 0.0 || $tokens <= 0) {
            return null;
        }

        return $tokens / $this->prefill;
    }

    /**
     * Bind to a model, forgetting figures learned from another: speed belongs
     * to the model and how it is loaded, and a 7B on the GPU differs from a 30B
     * spilled onto the CPU by two orders of magnitude.
     */
    public function useModel(string $model): void
    {
        $model = trim($model);

        if ($model === '' || $model === $this->model) {
            return;
        }

        $this->model = $model;
        $this->prefill = null;
        $this->decode = null;
        $this->samples = 0;
    }
}
