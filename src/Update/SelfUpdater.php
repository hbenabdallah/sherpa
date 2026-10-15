<?php

declare(strict_types=1);

namespace App\Update;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Replace the running executable with a release, the way install.sh does:
 * the file and its published SHA-256, compared before anything is touched,
 * then a rename over the old one — whole or not at all, never half a file
 * where Sherpa was.
 */
final class SelfUpdater
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly Releases $releases,
    ) {}

    /**
     * @param (\Closure(?string): void)|null $beforeReplace given what the
     *        release changed, for a release that says so, once everything is
     *        downloaded and checked and just before the executable is
     *        replaced: the last moment this process may load anything from it
     *
     * @throws UpdateFailed with the reason, the executable left as it was
     */
    public function install(string $version, string $platform, string $target, ?\Closure $beforeReplace = null): void
    {
        $dir = dirname($target);
        if (!is_writable($dir) || (is_file($target) && !is_writable($target))) {
            throw new UpdateFailed("cannot write in {$dir}. Run install.sh with --dir pointing there, as a user who can, or update by hand.");
        }

        $url = $this->releases->executable($version, $platform);
        $binary = $this->fetch($url, "no Sherpa {$version} for {$platform}");
        $sum = $this->fetch($url . '.sha256', "the checksum of Sherpa {$version} is missing from the release");

        $expected = strtolower((string) strtok(trim($sum), " \t\n"));
        if (preg_match('/^[0-9a-f]{64}$/', $expected) !== 1 || !hash_equals($expected, hash('sha256', $binary))) {
            throw new UpdateFailed('the SHA-256 of the download does not match the release\'s');
        }

        // Optional: the releases before 1.0.2 have none.
        try {
            $notes = $this->fetch($this->releases->notes($version), 'no notes');
        } catch (UpdateFailed) {
            $notes = null;
        }

        $tmp = $dir . '/.sherpa-update-' . getmypid();
        if (@file_put_contents($tmp, $binary) !== strlen($binary) || !@chmod($tmp, 0755)) {
            @unlink($tmp);

            throw new UpdateFailed("could not write in {$dir}");
        }

        if ($beforeReplace !== null) {
            $beforeReplace($notes);
        }

        if (!@rename($tmp, $target)) {
            @unlink($tmp);

            throw new UpdateFailed("could not replace {$target}");
        }
    }

    private function fetch(string $url, string $missing): string
    {
        try {
            $response = $this->client->request('GET', $url, ['timeout' => 30, 'max_duration' => 600]);
            if ($response->getStatusCode() !== 200) {
                throw new UpdateFailed("{$missing} (HTTP {$response->getStatusCode()})");
            }

            return $response->getContent();
        } catch (UpdateFailed $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new UpdateFailed("{$missing}: {$e->getMessage()}");
        }
    }
}
