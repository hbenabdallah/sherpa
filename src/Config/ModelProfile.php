<?php

declare(strict_types=1);

namespace App\Config;

use App\Platform\ModelInfo;

/**
 * The model this run will use, and the window it will ask for.
 *
 * These two travel together on purpose. They used to be two unrelated
 * environment variables — OLLAMA_MODEL and OLLAMA_CTX — read in two places and
 * handed to two services that each kept their own copy. Changing the model
 * without changing the window left Sherpa asking a 32k model for 256k, or a
 * 256k model for 32k, and nothing anywhere noticed.
 */
final class ModelProfile
{
    /**
     * The window used when nothing better is known.
     *
     * Deliberately modest. A model's native ceiling is what it *can* address,
     * not what fits in the machine in front of you: a 30B whose weights already
     * fill most of the VRAM has no room left for a quarter-million-token KV
     * cache, and Ollama does not refuse — it spills onto the CPU and the GPU
     * stops mattering. Starting low and letting someone raise it knowingly is
     * the only order that cannot silently ruin the thing they came for.
     */
    public const SAFE_CONTEXT = 32768;

    public function __construct(
        public readonly string $model,
        public readonly int $contextWindow,
        /** Where this came from, named for the user: "config machine", "-m", … */
        public readonly string $source = '',
    ) {}

    /**
     * The profile to propose for a model we have just learned about.
     *
     * Capped at the model's own ceiling, because asking beyond it is not a
     * bigger window — it is a request the backend has to reinterpret.
     */
    public static function suggestFor(ModelInfo $info, string $source = ''): self
    {
        $native = $info->contextLength;

        return new self(
            model: $info->name,
            contextWindow: $native === null || $native <= 0
                ? self::SAFE_CONTEXT
                : min($native, self::SAFE_CONTEXT),
            source: $source,
        );
    }

    public function withContext(int $contextWindow): self
    {
        return new self($this->model, max(1, $contextWindow), $this->source);
    }

    public function withSource(string $source): self
    {
        return new self($this->model, $this->contextWindow, $source);
    }

    public function describe(): string
    {
        $window = $this->contextWindow >= 1024
            ? (int) round($this->contextWindow / 1024) . 'k'
            : (string) $this->contextWindow;

        return $this->source === ''
            ? "{$this->model} · {$window} de contexte"
            : "{$this->model} · {$window} de contexte · {$this->source}";
    }
}
