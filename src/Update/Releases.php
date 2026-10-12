<?php

declare(strict_types=1);

namespace App\Update;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sherpa's releases on GitHub: which is the latest, and where each executable
 * is. Asked the way install.sh asks — the redirect of /releases/latest to its
 * tag — rather than through the API, which allows sixty requests an hour per
 * address and answers a shared office's sixty-first with an error.
 */
final class Releases
{
    public const URL = 'https://github.com/hbenabdallah/sherpa/releases';

    /** A release number as tagged: three numbers, nothing after. */
    public const VERSION = '/^\d+\.\d+\.\d+$/';

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $url = self::URL,
    ) {}

    /** The latest release's number ("1.0.2"), or null when GitHub cannot say in time. */
    public function latest(float $timeout): ?string
    {
        try {
            $response = $this->client->request('GET', $this->url . '/latest', [
                'max_redirects' => 0,
                'timeout'       => $timeout,
                'max_duration'  => $timeout,
            ]);
            $location = $response->getHeaders(false)['location'][0] ?? '';
        } catch (\Throwable) {
            return null;
        }

        return preg_match('#/tag/v(\d+\.\d+\.\d+)$#', $location, $m) === 1 ? $m[1] : null;
    }

    /** Where a release's executable for $platform is; its checksum is beside it, with .sha256. */
    public function executable(string $version, string $platform): string
    {
        return "{$this->url}/download/v{$version}/sherpa-{$version}-{$platform}";
    }

    /** What the release changed, published beside its executables. */
    public function notes(string $version): string
    {
        return "{$this->url}/download/v{$version}/release-notes.md";
    }

    /**
     * This machine, named as the releases name it, or null where there is no
     * executable for it — the platforms install.sh knows.
     */
    public static function platform(string $os = PHP_OS_FAMILY, ?string $arch = null): ?string
    {
        $arch ??= php_uname('m');

        return match (true) {
            $os === 'Linux' && in_array($arch, ['x86_64', 'amd64'], true)   => 'linux-x86_64',
            $os === 'Linux' && in_array($arch, ['aarch64', 'arm64'], true)  => 'linux-aarch64',
            $os === 'Darwin' && in_array($arch, ['arm64', 'aarch64'], true) => 'macos-aarch64',
            $os === 'Darwin' && $arch === 'x86_64'                          => 'macos-x86_64',
            default                                                         => null,
        };
    }
}
