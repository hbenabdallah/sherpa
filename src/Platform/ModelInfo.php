<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * What the backend knows about one model.
 *
 * Sherpa used to take the model on faith: a name in .env, a context window in
 * another variable next to it, and no relation between the two. Nothing checked
 * that the model existed, that it could call tools at all, or that the window
 * being asked for was one the model actually has. All three fail silently —
 * a model without tool support simply never calls anything, and Sherpa looks
 * broken rather than misconfigured.
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
     * Whether this model can call tools.
     *
     * Sherpa is a tool-calling loop and nothing else; a model that cannot call
     * tools cannot read a file, so the whole session is a conversation with
     * something that will never do anything. Worth refusing loudly.
     *
     * An older backend that reports no capabilities at all gets the benefit of
     * the doubt — absence of the field is not evidence of absence of tools.
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
