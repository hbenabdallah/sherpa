<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * What this machine has settled on: ~/.config/sherpa/config.yaml.
 *
 * Deliberately not .env. .env is inside the checkout and describes how to build
 * and start the container; this describes which model the person at this
 * keyboard wants. Keeping them apart is what lets the same clone run on a
 * laptop against a 7B and on a desktop against a 30B without either machine
 * editing a tracked file, and without a `git pull` ever touching the choice.
 *
 * It sits beside projects.yaml and the skills, in the directory already mounted
 * into the container at the same absolute path.
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
     * The stored profile, or null when this machine has never chosen.
     *
     * Null is the signal that triggers the first-run question, so a file that
     * exists but says nothing usable must read as null rather than as a profile
     * with empty fields — otherwise the setup screen never appears and Sherpa
     * runs against a model named "".
     */
    public function read(): ?ModelProfile
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

        if (!is_array($data)) {
            return null;
        }

        $model = $data['model'] ?? null;
        if (!is_string($model) || trim($model) === '') {
            return null;
        }

        $context = $data['context'] ?? null;

        return new ModelProfile(
            model: trim($model),
            contextWindow: is_numeric($context) && (int) $context > 0
                ? (int) $context
                : ModelProfile::SAFE_CONTEXT,
            source: 'config machine',
        );
    }

    /**
     * Write the choice down, preserving anything else the file holds.
     *
     * @return bool false when it could not be written — the caller says so
     *              rather than letting someone believe a choice was saved
     */
    public function save(ModelProfile $profile): bool
    {
        $path = $this->path();
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }

        $data = [];
        if ($this->exists()) {
            try {
                $existing = Yaml::parseFile($path);
                $data = is_array($existing) ? $existing : [];
            } catch (\Throwable) {
                $data = [];
            }
        }

        $data['model'] = $profile->model;
        $data['context'] = $profile->contextWindow;

        $header = "# Configuration de cette machine.\n"
            . "# Hors du dépôt : le même clone peut viser un modèle différent ici et ailleurs.\n"
            . "# Modifiable à la main, ou depuis Sherpa avec /model.\n";

        return @file_put_contents($path, $header . Yaml::dump($data, 4, 2)) !== false;
    }
}
