<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Which model this run uses, and why.
 *
 * The order is the whole point, so it is written once, here, rather than being
 * spread across the command as a chain of null coalescences:
 *
 *   1. --model / --ctx        this run only
 *   2. config.yaml            what this machine chose
 *   3. .env                   the bootstrap default, and what Docker passes in
 *
 * Between 2 and 3 sits the first-run question. It fires when this machine has
 * never chosen — not when .env is unset, because .env always has a value and a
 * shipped default would otherwise mean the question is never asked.
 */
final class ModelResolver
{
    public function __construct(
        private readonly MachineConfig $config,
        private readonly string $envModel,
        private readonly int $envContext,
    ) {}

    /** An explicit choice for this run, or null when neither flag was given. */
    public function fromCommandLine(?string $model, ?int $context): ?ModelProfile
    {
        $model = $model !== null ? trim($model) : null;

        if (($model === null || $model === '') && $context === null) {
            return null;
        }

        // --ctx alone retunes whatever model is already settled on, rather than
        // silently dragging the .env model along with it.
        $base = $model !== null && $model !== ''
            ? new ModelProfile($model, ModelProfile::SAFE_CONTEXT, 'ligne de commande')
            : ($this->stored() ?? $this->fallback())->withSource('ligne de commande');

        return $context !== null && $context > 0
            ? $base->withContext($context)
            : $base;
    }

    /** What this machine settled on, or null when it never has. */
    public function stored(): ?ModelProfile
    {
        return $this->config->read();
    }

    /** Last resort, and the non-interactive path. Asks nothing. */
    public function fallback(): ModelProfile
    {
        return new ModelProfile(
            model: $this->envModel,
            contextWindow: $this->envContext > 0 ? $this->envContext : ModelProfile::SAFE_CONTEXT,
            source: 'valeur par défaut (.env)',
        );
    }

    public function remember(ModelProfile $profile): bool
    {
        return $this->config->save($profile);
    }

    public function configPath(): string
    {
        return $this->config->path();
    }
}
