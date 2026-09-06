<?php

declare(strict_types=1);

namespace App;

/**
 * Which Sherpa this is: the release number written into the single executable
 * by bin/build-release.php, or, from a checkout, "dev" and the commit it is
 * at — "dev" alone says nothing about which code is running.
 */
final class Version
{
    private static ?string $current = null;

    public static function current(): string
    {
        return self::$current ??= self::read();
    }

    private static function read(): string
    {
        $root = dirname(__DIR__);
        $file = $root . '/VERSION';
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        if ($version !== '') {
            return $version;
        }

        // No git, or not a clone (the Docker image, an archive): "dev" it is.
        $described = is_dir($root . '/.git')
            ? trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' describe --tags --always --dirty=+modified 2>/dev/null'))
            : '';

        return $described !== '' ? "dev ({$described})" : 'dev';
    }
}
