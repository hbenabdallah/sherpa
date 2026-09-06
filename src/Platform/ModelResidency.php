<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * Where the loaded model actually sits: GPU memory, host memory, or both.
 *
 * This is the one fact that silently decides whether a machine with a graphics
 * card behaves like one. The window requested occupies VRAM *in addition* to
 * the weights, and when it no longer fits Ollama does not refuse — it spills
 * the overflow onto the CPU and carries on at a fraction of the speed. Nothing
 * in the reply says so. The README used to answer this by telling the user to
 * watch `ollama ps` by hand; that is a measurement, and measurements belong in
 * the program.
 */
final class ModelResidency
{
    public function __construct(
        public readonly int $totalBytes,
        public readonly int $vramBytes,
    ) {}

    /** False when the backend holds no copy of this model right now. */
    public function isLoaded(): bool
    {
        return $this->totalBytes > 0;
    }

    public function isFullyOnGpu(): bool
    {
        return $this->totalBytes > 0 && $this->vramBytes >= $this->totalBytes;
    }

    public function isCpuOnly(): bool
    {
        return $this->vramBytes <= 0;
    }

    /** 0.0 to 1.0. A partial figure is the interesting one: it means spilling. */
    public function gpuShare(): float
    {
        if ($this->totalBytes <= 0) {
            return 0.0;
        }

        return min(1.0, max(0.0, $this->vramBytes / $this->totalBytes));
    }

    public function describe(): string
    {
        if (!$this->isLoaded()) {
            return 'not loaded yet';
        }
        if ($this->isCpuOnly()) {
            return 'CPU seul';
        }
        if ($this->isFullyOnGpu()) {
            return '100 % GPU';
        }

        return sprintf('%d %% GPU, the rest spilled onto the CPU', (int) round($this->gpuShare() * 100));
    }
}
