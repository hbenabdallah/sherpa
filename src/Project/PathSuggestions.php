<?php

declare(strict_types=1);

namespace App\Project;

/**
 * Where a path the model asked for probably is.
 *
 * The model reads a tree like
 *
 *     src/
 *       Routing/ (1 entry)
 *
 * and asks for `Routing`. Or it asks for `.env` when the project has
 * `.env.example`. A bare "not found" makes the next turn another guess;
 * naming the real path makes it the right call.
 */
final class PathSuggestions
{
    /** Never searched: other people's code, generated output, VCS internals. */
    private const SKIPPED = ['vendor', 'node_modules', '.git', 'var', '.idea', '.vscode', '__pycache__', '.venv', 'target'];

    /** A project is walked this far and no further: a hint is not worth a slow error. */
    private const MAX_ENTRIES = 20_000;

    private const MAX_SUGGESTIONS = 5;

    /**
     * Paths, relative to $root, that $missing was most likely meant to be:
     * same name elsewhere in the project, or for a file, the same name with
     * something after it (`.env` → `.env.example`, `phpunit.xml` → `phpunit.xml.dist`).
     *
     * @return string[]
     */
    public static function for(string $root, string $missing, bool $directory): array
    {
        $root = rtrim($root, '/');
        $name = strtolower(basename(rtrim($missing, '/')));
        if ($name === '' || $name === '.' || $name === '..') {
            return [];
        }

        $found = [];
        $seen = 0;
        $queue = [''];

        while ($queue !== [] && $seen < self::MAX_ENTRIES) {
            $relative = array_shift($queue);
            $entries = @scandir($relative === '' ? $root : "{$root}/{$relative}");
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || ++$seen > self::MAX_ENTRIES) {
                    continue;
                }

                $path = $relative === '' ? $entry : "{$relative}/{$entry}";
                $full = "{$root}/{$path}";
                $isDir = is_dir($full) && !is_link($full);
                $lower = strtolower($entry);

                if ($isDir === $directory && ($lower === $name || (!$directory && str_starts_with($lower, $name . '.')))) {
                    $found[] = $path;
                }

                if ($isDir && !in_array($entry, self::SKIPPED, true)) {
                    $queue[] = $path;
                }
            }
        }

        usort($found, fn(string $a, string $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));

        return array_slice($found, 0, self::MAX_SUGGESTIONS);
    }

    /** " — did you mean src/Routing?" or nothing. */
    public static function hint(string $root, string $missing, bool $directory): string
    {
        $paths = self::for($root, $missing, $directory);

        return $paths === [] ? '' : ' — did you mean ' . implode(', ', $paths) . '?';
    }
}
