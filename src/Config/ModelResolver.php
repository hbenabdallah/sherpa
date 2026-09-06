<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Which backend and which model this run uses, and why.
 *
 * The order is the whole point, so it is written once, here, rather than being
 * spread across the command as a chain of null coalescences:
 *
 *   1. --backend / --model / --ctx   this run only
 *   2. config.yaml                   what this machine chose
 *   3. .env                          the bootstrap default, and what Docker passes in
 *   4. the API                       when nothing anywhere says otherwise
 *
 * The backend is settled first, since a model name only means something to the
 * backend serving it. Between 2 and 3 sits the first-run question, which fires
 * when this machine never chose — not when .env is unset, since .env always has
 * a value and the question would then never be asked.
 */
final class ModelResolver
{
    public function __construct(
        private readonly MachineConfig $config,
        // Ollama's fallback model and window. Named as they always were, since
        // they are what .env has always carried.
        private readonly string $envModel,
        private readonly int $envContext,
        private readonly string $envBackend = Backend::DEFAULT->value,
        private readonly string $envApiModel = '',
        private readonly int $envApiContext = ModelProfile::SAFE_CONTEXT,
        private readonly string $envEmbeddingModel = '',
    ) {}

    /**
     * The embedding model for $backend: this machine's choice, then the
     * environment, then none — keywords only.
     */
    public function embeddingModel(Backend $backend): string
    {
        return $this->config->embeddingModel($backend) ?? trim($this->envEmbeddingModel);
    }

    /**
     * The backend for this run: the flag, then this machine, then .env, then
     * the default. A value nobody can parse is passed over rather than trusted,
     * so the caller validates a flag before handing it in.
     */
    public function backend(?Backend $flag = null): Backend
    {
        return $flag
            ?? $this->config->backend()
            ?? Backend::parse($this->envBackend)
            ?? Backend::DEFAULT;
    }

    /** An explicit choice for this run, or null when neither flag was given. */
    public function fromCommandLine(?string $model, ?int $context, ?Backend $backend = null): ?ModelProfile
    {
        $backend ??= $this->backend();
        $model = $model !== null ? trim($model) : null;

        if (($model === null || $model === '') && $context === null) {
            return null;
        }

        // --ctx alone retunes whatever model is already settled on, rather than
        // silently dragging the .env model along with it.
        $base = $model !== null && $model !== ''
            ? new ModelProfile($model, ModelProfile::SAFE_CONTEXT, 'command line', $backend)
            : ($this->stored($backend) ?? $this->fallback($backend))->withSource('command line');

        return $context !== null && $context > 0
            ? $base->withContext($context)
            : $base;
    }

    /** What this machine settled on for that backend, or null when it never has. */
    public function stored(?Backend $backend = null): ?ModelProfile
    {
        return $this->config->read($backend ?? $this->backend());
    }

    /**
     * Last resort, and the non-interactive path. Asks nothing.
     *
     * For the API the model may well be empty: no provider's model can be
     * guessed, so an unconfigured machine gets "" and the caller explains what
     * to set rather than sending a request that will be refused.
     */
    public function fallback(?Backend $backend = null): ModelProfile
    {
        $backend ??= $this->backend();

        [$model, $context] = match ($backend) {
            Backend::Api    => [$this->envApiModel, $this->envApiContext],
            Backend::Ollama => [$this->envModel, $this->envContext],
        };

        return new ModelProfile(
            model: trim($model),
            contextWindow: $context > 0 ? $context : ModelProfile::SAFE_CONTEXT,
            source: 'from the environment',
            backend: $backend,
        );
    }

    /** @param bool $asDefault whether this profile's backend becomes the machine's own */
    public function remember(ModelProfile $profile, bool $asDefault = true): bool
    {
        return $this->config->save($profile, $asDefault);
    }

    /**
     * The API's address: SHERPA_API_URL when it is set, else what this machine
     * recorded. The variable wins, unlike the model's: it ships empty, so a
     * value there is someone's deliberate choice, made next to the key. A
     * provider named for this run (--provider, /provider) wins over both.
     */
    public function apiUrl(string $fromEnvironment): ?string
    {
        if ($this->config->providerChosenForRun() && $this->config->apiUrl() !== null) {
            return $this->config->apiUrl();
        }

        return trim($fromEnvironment) !== '' ? trim($fromEnvironment) : $this->config->apiUrl();
    }

    /**
     * The key for requests to $url, when $url is the active provider's: its
     * own variable when it names one and it is set, else the key recorded for
     * it, else null for SHERPA_API_KEY. Never another provider's key — '' for
     * one whose variable is unset, so the refusal says what is missing.
     */
    public function apiKey(string $url): ?string
    {
        $name = $this->config->activeProvider();
        if ($name === null || $url !== $this->config->apiUrl()) {
            return null;
        }

        $variable = $this->config->apiKeyVariable();
        if ($variable !== null) {
            $value = $_SERVER[$variable] ?? $_ENV[$variable] ?? getenv($variable);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $this->config->storedKey($name) ?? ($variable !== null ? '' : null);
    }

    public function saveKey(string $provider, string $key): bool
    {
        return $this->config->saveKey($provider, $key);
    }

    public function hasStoredKey(string $provider): bool
    {
        return $this->config->storedKey($provider) !== null;
    }

    public function keysPath(): string
    {
        return $this->config->keysPath();
    }

    /** @return array<string, array{url: ?string, key_env: ?string, model: ?string}> */
    public function providers(): array
    {
        return $this->config->providers();
    }

    public function activeProvider(): ?string
    {
        return $this->config->activeProvider();
    }

    /** The provider named for this run, null when it takes the machine's. */
    public function providerForRun(): ?string
    {
        return $this->config->sessionProvider();
    }

    public function useProvider(?string $name): void
    {
        $this->config->useProvider($name);
    }

    public function addProvider(string $name, string $url, ?string $keyVariable): bool
    {
        return $this->config->addProvider($name, $url, $keyVariable);
    }

    public function removeProvider(string $name): bool
    {
        return $this->config->removeProvider($name);
    }

    /** Whether the embedding model is already settled: by the environment, or by this machine. */
    public function embeddingChosen(Backend $backend): bool
    {
        return trim($this->envEmbeddingModel) !== '' || $this->config->hasEmbeddingChoice($backend);
    }

    public function rememberEmbeddingModel(Backend $backend, string $model): bool
    {
        return $this->config->saveEmbeddingModel($backend, $model);
    }

    public function rememberApiUrl(string $url): bool
    {
        return $this->config->saveApiUrl($url);
    }

    /** The price the user declared for this model, if any. */
    public function price(ModelProfile $profile): ?\App\Usage\Price
    {
        return $this->config->price($profile->model);
    }

    public function configPath(): string
    {
        return $this->config->path();
    }
}
