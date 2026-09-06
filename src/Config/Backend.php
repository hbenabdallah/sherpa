<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Where the model runs: rented from a provider over its API, or served on
 * hardware the user controls through Ollama.
 *
 * One choice for the whole machine, not one per project. Which model reads a
 * repository is decided by whoever runs Sherpa, with the same settings
 * wherever they run it.
 */
enum Backend: string
{
    case Api = 'api';
    case Ollama = 'ollama';

    /**
     * The API, because it works on any machine the moment a key is set. A
     * local model needs hardware that can carry one, and on a laptop without a
     * graphics card a single turn takes minutes.
     */
    public const DEFAULT = self::Api;

    /** Lenient on purpose: this is read from a flag, an env var, a hand-edited file. */
    public static function parse(?string $value): ?self
    {
        return match (strtolower(trim((string) $value))) {
            'api', 'openai', 'remote', 'en-ligne' => self::Api,
            'ollama', 'local'                      => self::Ollama,
            default                                => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Api    => 'API',
            self::Ollama => 'Ollama',
        };
    }

    /**
     * Whether the weights sit on hardware the user runs. It decides which
     * advice is worth giving — VRAM and `ollama pull` on one side, a key and a
     * provider's model list on the other — and whether code leaves the network
     * it was read on.
     */
    public function isLocal(): bool
    {
        return $this === self::Ollama;
    }
}
