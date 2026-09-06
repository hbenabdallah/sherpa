<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * What the backend knows about one model. Taken on faith, all three facts fail
 * silently: a model that does not exist, one that cannot call tools — it simply
 * never calls anything, and Sherpa looks broken rather than misconfigured — and
 * a window wider than the model has.
 */
final class ModelInfo
{
    /**
     * @param int|null            $contextLength the model's native ceiling, null when the
     *                                           backend did not report one
     * @param array<int, string>  $capabilities  as the backend names them: completion,
     *                                           tools, vision, insert…
     */
    public function __construct(
        public readonly string $name,
        public readonly ?int $contextLength = null,
        public readonly array $capabilities = [],
        public readonly ?int $sizeBytes = null,
        public readonly ?string $parameterSize = null,
    ) {}

    /**
     * Whether this model can call tools. Sherpa is a tool-calling loop and
     * nothing else, so a model that cannot is worth refusing loudly. A backend
     * that reports no capabilities gets the benefit of the doubt.
     */
    public function supportsTools(): bool
    {
        return $this->capabilities === [] || in_array('tools', $this->capabilities, true);
    }

    /** True when the backend said nothing either way. */
    public function capabilitiesUnknown(): bool
    {
        return $this->capabilities === [];
    }

    public function humanSize(): string
    {
        if ($this->sizeBytes === null || $this->sizeBytes <= 0) {
            return '';
        }

        $gb = $this->sizeBytes / 1_000_000_000;

        return $gb >= 10
            ? sprintf('%d GB', (int) round($gb))
            : sprintf('%.1f GB', $gb);
    }

    /** 262144 → "256k". The exact digits are noise at selection time. */
    public function humanContext(): string
    {
        if ($this->contextLength === null || $this->contextLength <= 0) {
            return '?';
        }

        return $this->contextLength >= 1024
            ? (int) round($this->contextLength / 1024) . 'k'
            : (string) $this->contextLength;
    }
}
