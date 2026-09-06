<?php

declare(strict_types=1);

namespace App\Config;

use App\Usage\Price;
use Symfony\Component\Yaml\Yaml;

/**
 * What this machine has settled on: ~/.config/sherpa/config.yaml.
 *
 *     backend: api
 *     api:
 *       provider: groq
 *       providers:
 *         groq:
 *           url: https://api.groq.com/openai/v1
 *           key_env: GROQ_API_KEY
 *           model: <the provider's id>
 *           context: 32768
 *     ollama:
 *       model: qwen2.5-coder:7b
 *       context: 32768
 *
 * Deliberately not .env, which describes how to start the container: this says
 * which model the person at this keyboard wants, so the same clone runs a 7B on
 * a laptop and a 30B on a desktop without editing a tracked file.
 *
 * The API holds several providers, one of them in force. Their keys are not
 * in this file, which gets shown and pasted around: they are in keys.yaml
 * beside it, readable by its owner only — or in the variable a provider
 * names (key_env). Switching providers never sends one's key to another.
 */
class MachineConfig
{
    private ?string $overridePath = null;

    /** The provider chosen for this run only (--provider, /provider), over the file's. */
    private ?string $sessionProvider = null;

    /** Test seam. */
    public function setPath(?string $path): void
    {
        $this->overridePath = $path;
    }

    public function path(): string
    {
        return $this->overridePath
            ?? (($_SERVER['HOME'] ?? getenv('HOME')) . '/.config/sherpa/config.yaml');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * Which backend this machine chose, or null when it never said. A file
     * written before there was a choice holds a bare model, which can only be
     * an Ollama one: reading it as the new default would point an existing
     * install at an API it was never configured for.
     */
    public function backend(): ?Backend
    {
        $data = $this->data();

        if ($data === null) {
            return null;
        }

        if (isset($data['backend'])) {
            return Backend::parse(is_string($data['backend']) ? $data['backend'] : null);
        }

        return $this->legacyModel($data) !== null ? Backend::Ollama : null;
    }

    /**
     * The stored profile for $backend, or null when nothing usable was chosen.
     * Null is what triggers the first-run question, so a file that says nothing
     * usable must read as null rather than as a profile with empty fields.
     */
    public function read(?Backend $backend = null): ?ModelProfile
    {
        $data = $this->data();

        if ($data === null) {
            return null;
        }

        $backend ??= $this->backend();
        if ($backend === null) {
            return null;
        }

        $section = $this->section($data, $backend) ?: null;

        // The file as it was before backends: a bare model at the top level,
        // which can only have been an Ollama one.
        if ($section === null && $backend === Backend::Ollama && $this->legacyModel($data) !== null) {
            $section = $data;
        }

        $model = $section['model'] ?? null;
        if (!is_string($model) || trim($model) === '') {
            return null;
        }

        $context = $section['context'] ?? null;

        return new ModelProfile(
            model: trim($model),
            contextWindow: is_numeric($context) && (int) $context > 0
                ? (int) $context
                : ModelProfile::SAFE_CONTEXT,
            source: 'machine config',
            backend: $backend,
        );
    }

    /**
     * Write the choice down, keeping whatever else the file holds. Each backend
     * keeps its own model; $asDefault says whether it also becomes the
     * machine's own, which a choice made under `-b` does not.
     *
     * @return bool false when it could not be written
     */
    public function save(ModelProfile $profile, bool $asDefault = true): bool
    {
        $data = $this->data() ?? [];

        // A file from before backends is folded into the section it always
        // described, so the two shapes never coexist in one file.
        $legacy = $this->legacyModel($data);
        if ($legacy !== null) {
            $data[Backend::Ollama->value] ??= array_filter(
                ['model' => $legacy, 'context' => $data['context'] ?? null],
                fn($v) => $v !== null,
            );
            $data['backend'] ??= Backend::Ollama->value;
        }
        unset($data['model'], $data['context']);

        if ($asDefault || !isset($data['backend'])) {
            $data['backend'] = $profile->backend->value;
        }

        // Merged, not replaced: the API's section also holds its address.
        $data = $this->withSection($data, $profile->backend, [
            'model'   => $profile->model,
            'context' => $profile->contextWindow,
        ] + $this->section($data, $profile->backend));

        // A model kept for this machine keeps its provider too: "this one,
        // from now on" cannot mean the model without where it is served.
        if ($asDefault && $profile->backend === Backend::Api && isset($data['api']['providers'])) {
            $data['api']['provider'] = $this->activeIn($data);
        }

        return $this->write($data);
    }

    /**
     * The API's address as this machine recorded it, or null. Not a secret, so
     * it can live here — unlike the key, which only comes from the environment.
     * Recorded by the first-run question rather than left to the documentation.
     */
    public function apiUrl(): ?string
    {
        $url = $this->section($this->data() ?? [], Backend::Api)['url'] ?? null;

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    /**
     * The model that turns documentation into vectors on $backend, or null.
     *
     *     api:
     *       embedding_model: '@cf/baai/bge-m3'
     *
     * Per backend, like the chat model, since a name only means something to
     * the server serving it. Absent, /docs says search is by keywords alone.
     */
    public function embeddingModel(Backend $backend): ?string
    {
        $model = $this->section($this->data() ?? [], $backend)['embedding_model'] ?? null;

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    /** Whether this machine settled the question for $backend — a model, or none found (''). */
    public function hasEmbeddingChoice(Backend $backend): bool
    {
        return array_key_exists('embedding_model', $this->section($this->data() ?? [], $backend));
    }

    /** '' records that detection found none, so it is not asked again. */
    public function saveEmbeddingModel(Backend $backend, string $model): bool
    {
        $data = $this->data() ?? [];
        $section = $this->section($data, $backend);
        $section['embedding_model'] = $model;

        return $this->write($this->withSection($data, $backend, $section));
    }

    public function saveApiUrl(string $url): bool
    {
        $data = $this->data() ?? [];
        $section = $this->section($data, Backend::Api);
        // Another provider: the embedding model recorded for the old one
        // means nothing there, and detection runs again.
        if (($section['url'] ?? null) !== $url) {
            unset($section['embedding_model']);
        }

        return $this->write($this->withSection($data, Backend::Api, ['url' => $url] + $section));
    }

    /**
     * The API providers this machine knows, by name.
     *
     * @return array<string, array{url: ?string, key_env: ?string, model: ?string}>
     */
    public function providers(): array
    {
        $providers = [];
        foreach ($this->data()['api']['providers'] ?? [] as $name => $section) {
            if (!is_array($section)) {
                continue;
            }
            $providers[(string) $name] = [
                'url'     => self::text($section['url'] ?? null),
                'key_env' => self::text($section['key_env'] ?? null),
                'model'   => self::text($section['model'] ?? null),
            ];
        }

        return $providers;
    }

    /** The provider in force: this run's, else the machine's, else the only one. Null before any. */
    public function activeProvider(): ?string
    {
        return $this->activeIn($this->data() ?? []);
    }

    /** Use $name for this run without making it the machine's; null goes back to the machine's. */
    public function useProvider(?string $name): void
    {
        $this->sessionProvider = $name;
    }

    /** The provider named for this run, as it was named; null when none was. */
    public function sessionProvider(): ?string
    {
        return $this->sessionProvider;
    }

    /** Whether this run chose its provider, rather than taking the machine's. */
    public function providerChosenForRun(): bool
    {
        return $this->sessionProvider !== null && isset($this->providers()[$this->sessionProvider]);
    }

    /** The variable the active provider's key is in, or null when it names none. */
    public function apiKeyVariable(): ?string
    {
        return self::text($this->section($this->data() ?? [], Backend::Api)['key_env'] ?? null);
    }

    /**
     * Record a provider. An existing name is replaced, keeping the model and
     * the embedding model chosen for it when the address stays the same.
     */
    public function addProvider(string $name, string $url, ?string $keyVariable): bool
    {
        $data = $this->data() ?? [];
        $old = is_array($data['api']['providers'][$name] ?? null) ? $data['api']['providers'][$name] : [];
        $section = ($old['url'] ?? null) === $url ? $old : [];
        unset($section['key_env']);

        $data['api']['providers'][$name] = array_filter(
            ['url' => $url, 'key_env' => $keyVariable],
            fn($v) => $v !== null && $v !== '',
        ) + $section;
        $data['api']['provider'] ??= $name;

        return $this->write($data);
    }

    /**
     * The key recorded for provider $name: null when none was, '' when it was
     * recorded as wanting none — so SHERPA_API_KEY is not sent there instead.
     */
    public function storedKey(string $name): ?string
    {
        $key = $this->keys()[$name] ?? null;

        return is_string($key) ? $key : null;
    }

    /** Record $name's key, in a file only its owner can read. */
    public function saveKey(string $name, string $key): bool
    {
        $keys = $this->keys();
        $keys[$name] = trim($key);

        return $this->writeKeys($keys);
    }

    public function forgetKey(string $name): bool
    {
        $keys = $this->keys();
        unset($keys[$name]);

        return $this->writeKeys($keys);
    }

    public function keysPath(): string
    {
        return dirname($this->path()) . '/keys.yaml';
    }

    /** @return array<string, mixed> */
    private function keys(): array
    {
        if (!is_file($this->keysPath())) {
            return [];
        }

        try {
            $keys = Yaml::parseFile($this->keysPath());
        } catch (\Throwable) {
            return [];
        }

        return is_array($keys) ? $keys : [];
    }

    /** @param array<string, mixed> $keys */
    private function writeKeys(array $keys): bool
    {
        $path = $this->keysPath();
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) {
            return false;
        }

        // Closed before a byte of a key is in it.
        if (!is_file($path) && @touch($path) === false) {
            return false;
        }
        @chmod($path, 0600);

        return @file_put_contents($path, "# Sherpa's API keys, by provider. Readable by you only; never share this file.\n"
            . Yaml::dump($keys, 2, 2)) !== false;
    }

    /** Forget a provider. The one in force cannot go: the session is using it. */
    public function removeProvider(string $name): bool
    {
        $data = $this->data() ?? [];
        if (!isset($data['api']['providers'][$name]) || $name === $this->activeIn($data)) {
            return false;
        }

        unset($data['api']['providers'][$name]);
        if (($data['api']['provider'] ?? null) === $name) {
            $data['api']['provider'] = array_key_first($data['api']['providers']);
        }

        return $this->write($data) && ($this->storedKey($name) === null || $this->forgetKey($name));
    }

    /**
     * The settings of $backend in force: Ollama's section, or the active
     * provider's.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function section(array $data, Backend $backend): array
    {
        if ($backend === Backend::Ollama) {
            return is_array($data['ollama'] ?? null) ? $data['ollama'] : [];
        }

        $name = $this->activeIn($data);
        $section = $name !== null ? ($data['api']['providers'][$name] ?? null) : null;

        return is_array($section) ? $section : [];
    }

    /**
     * $data with $section as the settings of $backend in force. The first API
     * setting on a machine with no provider yet creates one, named after its
     * address.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $section
     *
     * @return array<string, mixed>
     */
    private function withSection(array $data, Backend $backend, array $section): array
    {
        if ($backend === Backend::Ollama) {
            $data['ollama'] = $section;

            return $data;
        }

        $name = $this->activeIn($data) ?? ProviderName::fromUrl(self::text($section['url'] ?? null));
        $data['api']['provider'] ??= $name;
        $data['api']['providers'][$name] = $section;

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function activeIn(array $data): ?string
    {
        $providers = is_array($data['api']['providers'] ?? null) ? $data['api']['providers'] : [];

        foreach ([$this->sessionProvider, $data['api']['provider'] ?? null] as $name) {
            if (is_string($name) && isset($providers[$name])) {
                return $name;
            }
        }

        $first = array_key_first($providers);

        return $first !== null ? (string) $first : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): bool
    {
        $path = $this->path();
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }

        // Backend first, where someone opening the file looks.
        if (isset($data['backend'])) {
            $data = ['backend' => $data['backend']] + $data;
        }

        $header = "# This machine's configuration.\n"
            . "# Outside the repository: the same clone can aim at a different model here and there.\n"
            . "# backend: api (an online provider) or ollama (a local model).\n"
            . "# API keys are never written here: they are in keys.yaml beside it (readable by you\n"
            . "# only), in the variable a provider names (key_env), or else in SHERPA_API_KEY.\n"
            . "# Edit by hand, or from Sherpa with /model, /backend and /provider.\n";

        return @file_put_contents($path, $header . Yaml::dump($data, 4, 2)) !== false;
    }

    /**
     * What $model costs, as the user declared it, or null.
     *
     *     prices:
     *       'provider/model': { input: 0.15, output: 0.60, cached_input: 0.03, currency: '$' }
     *
     * Per million tokens, keyed by model. Unreadable counts as absent: half a
     * price makes a wrong number shown with confidence.
     */
    public function price(string $model): ?Price
    {
        $entry = $this->data()['prices'][$model] ?? null;

        if (!is_array($entry) || !is_numeric($entry['input'] ?? null) || !is_numeric($entry['output'] ?? null)) {
            return null;
        }

        $cached = $entry['cached_input'] ?? null;
        $currency = $entry['currency'] ?? null;

        return new Price(
            input: (float) $entry['input'],
            output: (float) $entry['output'],
            cachedInput: is_numeric($cached) ? (float) $cached : null,
            currency: is_string($currency) && trim($currency) !== '' ? trim($currency) : '$',
        );
    }

    /**
     * The parsed file, or null when there is none or it cannot be read.
     *
     * @return array<string, mixed>|null
     */
    private function data(): ?array
    {
        if (!$this->exists()) {
            return null;
        }

        try {
            $data = Yaml::parseFile($this->path());
        } catch (\Throwable) {
            // A hand-edited file with a typo in it. Better to fall through to
            // the defaults and say so than to refuse to start.
            return null;
        }

        return is_array($data) ? self::withProviders($data) : null;
    }

    /**
     * A file from before providers: one flat api section, read as a single
     * provider named after its address. Written back in the new shape at the
     * next save, so the two never coexist.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function withProviders(array $data): array
    {
        $api = $data['api'] ?? null;
        if (!is_array($api) || isset($api['providers'])) {
            return $data;
        }

        $name = ProviderName::fromUrl(self::text($api['url'] ?? null));
        $data['api'] = ['provider' => $name, 'providers' => [$name => $api]];

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function legacyModel(array $data): ?string
    {
        $model = $data['model'] ?? null;

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }
}
