<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * Where the loaded model actually sits: GPU memory, host memory, or both.
 *
 * The window occupies VRAM on top of the weights, and when it does not fit
 * Ollama does not refuse: it spills onto the CPU and runs at a fraction of the
 * speed, and nothing in the reply says so. Measured here so Sherpa can.
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
