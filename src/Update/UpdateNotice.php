<?php

declare(strict_types=1);

namespace App\Update;

use App\Kernel;
use App\Version;

/**
 * One line at start-up when a newer Sherpa is out. Before it, a release
 * reached nobody who did not go and look.
 *
 * Asked of GitHub at most once a day, with a short timeout, and the answer
 * kept in the cache: a session never waits on it for more than a moment, and
 * offline it says nothing. Only the executable asks — a checkout is updated
 * with git, and "dev" has no number to compare. SHERPA_UPDATE_CHECK=off turns
 * it off; nothing but the request for the latest release number is sent.
 */
final readonly class UpdateNotice
{
    private const int DAY = 86_400;

    private const float TIMEOUT = 1.5;

    public function __construct(
        private Releases $releases,
        private ?string $cacheFile = null,
        private ?string $current = null,
    ) {}

    /** What to tell the user, or null when there is nothing newer, or no telling. */
    public function message(?int $now = null): ?string
    {
        if (strtolower(trim((string) getenv('SHERPA_UPDATE_CHECK'))) === 'off') {
            return null;
        }

        $current = $this->current ?? Version::current();
        if (preg_match(Releases::VERSION, $current) !== 1) {
            return null;
        }

        $latest = $this->latest($now ?? time());

        return $latest !== null && version_compare($latest, $current, '>')
            ? "Sherpa {$latest} is available (you have {$current}): sherpa update"
            : null;
    }

    private function latest(int $now): ?string
    {
        $file = $this->cacheFile ?? Kernel::userCacheDir() . '/latest-release.json';
        $cached = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

        if (is_array($cached) && is_int($cached['checked_at'] ?? null) && $now - $cached['checked_at'] < self::DAY
            && $cached['checked_at'] <= $now) {
            $latest = $cached['latest'] ?? null;

            return is_string($latest) && preg_match(Releases::VERSION, $latest) === 1 ? $latest : null;
        }

        $latest = $this->releases->latest(self::TIMEOUT);

        // Recorded even when GitHub did not answer: offline, every start would
        // otherwise wait out the timeout again.
        $dir = dirname($file);
        if (is_dir($dir) || @mkdir($dir, 0755, true)) {
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode(['checked_at' => $now, 'latest' => $latest])) !== false) {
                @rename($tmp, $file);
            }
            @unlink($tmp);
        }

        return $latest;
    }
}
