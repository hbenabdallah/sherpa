<?php

declare(strict_types=1);

namespace App\Project;

/**
 * Confines every filesystem tool to the current project.
 *
 * Without this, file_read runs at AUTO permission and accepts absolute paths,
 * so a model that asks for ~/.ssh/id_rsa or /etc/passwd simply gets it — no
 * confirmation, no trace. Sherpa also runs with the user's home mounted, so
 * "outside the project" means the whole machine.
 *
 * Resolution happens in two stages, and both matter:
 *   1. lexical normalisation, which collapses ".." before it ever hits disk
 *   2. realpath() on the deepest existing ancestor, which defeats symlinks
 *      pointing out of the tree
 *
 * Doing only (1) lets a symlink escape; doing only (2) fails for files that do
 * not exist yet, which is exactly the file_write case.
 */
final class ProjectPathResolver
{
    private ?string $root = null;

    public function setRoot(string $path): void
    {
        $real = realpath($path);

        if ($real === false) {
            throw new \InvalidArgumentException("Project path does not exist: {$path}");
        }

        $this->root = rtrim($real, '/');
    }

    public function root(): string
    {
        return $this->root ?? throw new \LogicException(
            'ProjectPathResolver: no project root set. Call setRoot() first.'
        );
    }

    public function hasRoot(): bool
    {
        return $this->root !== null;
    }

    /**
     * Absolute, symlink-resolved path guaranteed to sit inside the project.
     *
     * @throws PathOutsideProjectException
     */
    public function resolve(string $path): string
    {
        $root = $this->root();

        $candidate = str_starts_with($path, '/') ? $path : $root . '/' . $path;
        $resolved = $this->realpathAllowingMissing($this->normalize($candidate));

        if ($resolved !== $root && !str_starts_with($resolved, $root . '/')) {
            throw new PathOutsideProjectException($path, $root);
        }

        return $resolved;
    }

    /**
     * Project-relative form, for display and for keeping tool output readable.
     */
    public function relative(string $absolute): string
    {
        $root = $this->root();

        if ($absolute === $root) {
            return '.';
        }

        return str_starts_with($absolute, $root . '/')
            ? substr($absolute, strlen($root) + 1)
            : $absolute;
    }

    /**
     * Collapse "." and ".." textually, without touching the filesystem.
     */
    private function normalize(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    /**
     * realpath() that tolerates a path whose tail does not exist yet: resolve
     * the deepest existing ancestor, then re-attach the remaining segments.
     */
    private function realpathAllowingMissing(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'));

        for ($i = count($segments); $i > 0; $i--) {
            $prefix = '/' . implode('/', array_slice($segments, 0, $i));

            // Use lstat semantics via file_exists on the ancestor; a broken
            // symlink is treated as non-existent and we keep walking up.
            if (!file_exists($prefix)) {
                continue;
            }

            $real = realpath($prefix);
            if ($real === false) {
                continue;
            }

            $tail = array_slice($segments, $i);

            return $tail === [] ? $real : rtrim($real, '/') . '/' . implode('/', $tail);
        }

        return $path;
    }
}
