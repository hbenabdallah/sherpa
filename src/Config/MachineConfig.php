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
 *       model: <the provider's id>
 *       context: 32768
 *     ollama:
 *       model: qwen2.5-coder:7b
 *       context: 32768
 *
 * Deliberately not .env, which describes how to start the container: this says
 * which model the person at this keyboard wants, so the same clone runs a 7B on
 * a laptop and a 30B on a desktop without editing a tracked file.
 */
class MachineConfig
{
    private ?string $overridePath = null;

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

        $section = is_array($data[$backend->value] ?? null) ? $data[$backend->value] : null;

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
        $section = is_array($data[$profile->backend->value] ?? null) ? $data[$profile->backend->value] : [];
        $data[$profile->backend->value] = [
            'model'   => $profile->model,
            'context' => $profile->contextWindow,
        ] + $section;

        return $this->write($data);
    }

    /**
     * The API's address as this machine recorded it, or null. Not a secret, so
     * it can live here — unlike the key, which only comes from the environment.
     * Recorded by the first-run question rather than left to the documentation.
     */
    public function apiUrl(): ?string
    {
        $url = $this->data()[Backend::Api->value]['url'] ?? null;

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
        $model = $this->data()[$backend->value]['embedding_model'] ?? null;

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    /** Whether this machine settled the question for $backend — a model, or none found (''). */
    public function hasEmbeddingChoice(Backend $backend): bool
    {
        $section = $this->data()[$backend->value] ?? null;

        return is_array($section) && array_key_exists('embedding_model', $section);
    }

    /** '' records that detection found none, so it is not asked again. */
    public function saveEmbeddingModel(Backend $backend, string $model): bool
    {
        $data = $this->data() ?? [];
        $section = is_array($data[$backend->value] ?? null) ? $data[$backend->value] : [];
        $section['embedding_model'] = $model;
        $data[$backend->value] = $section;

        return $this->write($data);
    }

    public function saveApiUrl(string $url): bool
    {
        $data = $this->data() ?? [];
        $section = is_array($data[Backend::Api->value] ?? null) ? $data[Backend::Api->value] : [];
        // Another provider: the embedding model recorded for the old one
        // means nothing there, and detection runs again.
        if (($section['url'] ?? null) !== $url) {
            unset($section['embedding_model']);
        }
        $data[Backend::Api->value] = ['url' => $url] + $section;

        return $this->write($data);
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
            . "# The API key is never written here: it comes from SHERPA_API_KEY.\n"
            . "# Edit by hand, or from Sherpa with /model and /backend.\n";

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

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    private function legacyModel(array $data): ?string
    {
        $model = $data['model'] ?? null;

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }
}
