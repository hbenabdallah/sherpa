<?php

declare(strict_types=1);

namespace App;

/**
 * Which Sherpa this is: the release number written into the single executable
 * by bin/build-release.php, or "dev" when running from a checkout.
 */
final class Version
{
    public static function current(): string
    {
        $file = dirname(__DIR__) . '/VERSION';
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $version !== '' ? $version : 'dev';
    }
}
