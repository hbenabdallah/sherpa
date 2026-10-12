<?php

declare(strict_types=1);

namespace App\Project;

/**
 * A project whose task file already goes through docker: `make test` is
 * `docker compose run … php tests/run.php`. Its commands work from the
 * machine, and inside its container — no docker client, no socket there —
 * `make test` cannot work at all. Sherpa's own repository is one.
 */
final class DockerWrapper
{
    private const FILES = ['Makefile', 'makefile', 'GNUmakefile', 'justfile', 'Justfile'];

    /**
     * `docker run|exec`, `docker compose run|exec` or `docker-compose run|exec`,
     * flags allowed in between, on one command: not a `docker compose up && npm
     * run dev`, which only starts the containers.
     */
    private const CALL = '/\bdocker(?:-compose|\s+compose)?\s+(?:[^\n;&|]*\s)?(?:run|exec)\b/';

    /** The task file in $path that calls docker run or exec itself, or null. */
    public static function in(string $path): ?string
    {
        foreach (self::FILES as $file) {
            $content = is_file($path . '/' . $file) ? (string) @file_get_contents($path . '/' . $file) : '';
            if (preg_match(self::CALL, $content) === 1) {
                return $file;
            }
        }

        return null;
    }
}
