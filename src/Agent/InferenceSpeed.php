<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * How fast this machine actually generates, measured rather than configured.
 *
 * Sherpa was written on a machine without a graphics card, and a dozen
 * constants across the codebase quietly encoded that: how many iterations a
 * turn may take, how much shell output is worth keeping, whether it is
 * affordable to ask the model to summarise its own history. Every one of them
 * was a guess about speed, frozen as a number.
 *
 * The measurement was there all along. Ollama closes every reply with
 * `prompt_eval_duration` and `eval_duration` next to the token counts
 * ContextBudget already reads; the durations were being parsed past and thrown
 * away. Two divisions turn them into tokens per second, for free, on every
 * turn — no probe, no benchmark, no flag.
 *
 * So this class is the speed half of what ContextBudget does for size: start
 * with nothing, learn from what the server reports, and let policy ask instead
 * of assume. A machine without a GPU is then the slow end of one scale rather
 * than a special case with its own branch.
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
     * Estimated seconds to re-read a prompt of this size.
     *
     * This is the question every caller actually has. Compaction rewrites
     * history from the front, which costs a full re-read of the window on the
     * next request; whether that is worth doing, and in which order, is a
     * number of seconds rather than a category of machine.
     */
    public function secondsToPrefill(int $tokens): ?float
    {
        if ($this->prefill === null || $this->prefill <= 0.0 || $tokens <= 0) {
            return null;
        }

        return $tokens / $this->prefill;
    }

    /**
     * Bind to a model, forgetting figures learned from another one.
     *
     * Same reasoning as ContextBudget::useModel(): speed is a property of the
     * model and how it is loaded, not of the room. A 7B fully on the GPU and a
     * 30B half spilled onto the CPU differ by two orders of magnitude, and
     * carrying the first one's numbers into the second's session is how a
     * cheap decision gets made on an expensive machine.
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
