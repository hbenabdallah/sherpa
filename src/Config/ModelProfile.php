<?php

declare(strict_types=1);

namespace App\Config;

use App\Platform\ModelInfo;

/**
 * The model this run uses, the window it asks for, and the backend serving it —
 * together on purpose. As separate variables, changing one left Sherpa asking a
 * 32k model for 256k with nothing noticing; and a model name means nothing
 * without its backend, since the same string can be a tag and a hosted id.
 */
final class ModelProfile
{
    /**
     * The window used when nothing better is known, deliberately modest: a
     * model's ceiling is what it can address, not what fits in the machine in
     * front of you, and Ollama spills onto the CPU rather than refuse. On a
     * paid API it is a spending decision. Starting low is the only order that
     * cannot silently ruin what someone came for.
     */
    public const SAFE_CONTEXT = 32768;

    public function __construct(
        public readonly string $model,
        public readonly int $contextWindow,
        /** Where this came from, named for the user: "machine config", "-m", … */
        public readonly string $source = '',
        public readonly Backend $backend = Backend::DEFAULT,
    ) {}

    /**
     * The profile to propose for a model we have just learned about.
     *
     * Capped at the model's own ceiling, because asking beyond it is not a
     * bigger window — it is a request the backend has to reinterpret.
     */
    public static function suggestFor(ModelInfo $info, string $source = '', Backend $backend = Backend::DEFAULT): self
    {
        $native = $info->contextLength;

        return new self(
            model: $info->name,
            contextWindow: $native === null || $native <= 0
                ? self::SAFE_CONTEXT
                : min($native, self::SAFE_CONTEXT),
            source: $source,
            backend: $backend,
        );
    }

    public function withContext(int $contextWindow): self
    {
        return new self($this->model, max(1, $contextWindow), $this->source, $this->backend);
    }

    public function withSource(string $source): self
    {
        return new self($this->model, $this->contextWindow, $source, $this->backend);
    }

    /** Same model on the same backend: switching to it discards nothing. */
    public function sameModelAs(?self $other): bool
    {
        return $other !== null && $other->backend === $this->backend && $other->model === $this->model;
    }

    public function describe(): string
    {
        $window = $this->contextWindow >= 1024
            ? (int) round($this->contextWindow / 1024) . 'k'
            : (string) $this->contextWindow;

        $base = "{$this->model} · {$this->backend->label()} · {$window} de contexte";

        return $this->source === '' ? $base : "{$base} · {$this->source}";
    }
}
