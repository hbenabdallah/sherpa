<?php

// Builds the single executable: Sherpa packed in a phar, appended to the
// static PHP "micro" runtime from static-php-cli. One file to download, nothing
// to install — no PHP, no Docker, no Composer.
//
//   php -d phar.readonly=0 bin/build-release.php <micro.sfx> <out-dir> <version> [<platform>]
//
// Run by `make release`, which fetches micro.sfx against a pinned checksum.
// Writes <out-dir>/sherpa-<version>-<platform>, its .sha256 and
// THIRD-PARTY-NOTICES.md.

declare(strict_types=1);

[, $micro, $out, $version, $platform] = $argv + [null, null, null, null, 'linux-x86_64'];
if ($micro === null || $out === null || $version === null || !is_file($micro)) {
    fwrite(STDERR, "Usage: php -d phar.readonly=0 bin/build-release.php <micro.sfx> <out-dir> <version> [<platform>]\n");
    exit(2);
}
if (ini_get('phar.readonly')) {
    fwrite(STDERR, "phar.readonly is on: run with php -d phar.readonly=0\n");
    exit(2);
}

$root = dirname(__DIR__);
@mkdir($out, 0755, true);
$out = realpath($out);

// What the executable needs, and only that: no tests, no benchmark, no .env.
$entries = ['composer.json', 'src', 'config', 'vendor', 'bin/sherpa-release.php'];

$phar = $out . '/sherpa.phar';
@unlink($phar);
$archive = new Phar($phar, 0, 'sherpa.phar');
$archive->startBuffering();

// One pass: adding files one by one rewrites the manifest each time, and took
// minutes for vendor/.
$files = [];
foreach ($entries as $entry) {
    $path = "{$root}/{$entry}";
    if (is_file($path)) {
        $files[$entry] = $path;
        continue;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);
        // symfony/runtime is how the checkout boots; the executable boots itself.
        if ($relative !== 'vendor/autoload_runtime.php') {
            $files[$relative] = $file->getPathname();
        }
    }
}
$archive->buildFromIterator(new ArrayIterator($files));
$count = count($files);

$archive->addFromString('VERSION', $version . "\n");
$archive->setStub(<<<'STUB'
<?php
Phar::mapPhar('sherpa.phar');
require 'phar://sherpa.phar/bin/sherpa-release.php';
__HALT_COMPILER();
STUB);
$archive->stopBuffering();
unset($archive);

$binary = "{$out}/sherpa-{$version}-{$platform}";
file_put_contents($binary, file_get_contents($micro) . file_get_contents($phar));
chmod($binary, 0755);
unlink($phar);

file_put_contents("{$binary}.sha256", hash_file('sha256', $binary) . '  ' . basename($binary) . "\n");
file_put_contents("{$out}/THIRD-PARTY-NOTICES.md", notices($root));

printf("✓ %s — %d files, %.1f MB\n", $binary, $count, filesize($binary) / 1048576);

/**
 * The licences that ship inside the executable: every Composer package with
 * its licence text, then PHP and what static-php-cli links into it. Their
 * licences require these to travel with the binary.
 */
function notices(string $root): string
{
    $installed = json_decode((string) file_get_contents("{$root}/vendor/composer/installed.json"), true);
    $packages = $installed['packages'] ?? $installed;
    usort($packages, static fn(array $a, array $b) => strcmp($a['name'], $b['name']));

    $text = "# Third-party notices\n\n"
        . "The Sherpa executable bundles the software below. Each is distributed under its own\n"
        . "licence, reproduced here.\n\n"
        . "## PHP runtime\n\n"
        . "- **PHP** " . PHP_VERSION . " — PHP License v3.01 — https://www.php.net/license/3_01.txt\n"
        . "- Built by **static-php-cli** (MIT) — https://github.com/crazywhalecc/static-php-cli — which links\n"
        . "  statically, among others: SQLite (public domain), OpenSSL (Apache-2.0), curl (curl licence),\n"
        . "  zlib (zlib licence), libxml2 (MIT), oniguruma (BSD-2-Clause). Their licences are listed at\n"
        . "  https://static-php.dev.\n\n"
        . "## PHP packages\n\n";

    foreach ($packages as $package) {
        $name = $package['name'];
        $licence = implode(', ', $package['license'] ?? ['unknown']);
        $text .= "### {$name} {$package['version']}\n\nLicence: {$licence}\n\n";

        // No GLOB_BRACE: the static PHP is built on musl, which lacks it.
        $licences = array_filter(scandir("{$root}/vendor/{$name}") ?: [], static fn(string $f) => preg_match('/^(LICEN[CS]E|COPYING)/i', $f) === 1);
        if ($licences !== []) {
            $text .= "```\n" . trim((string) file_get_contents("{$root}/vendor/{$name}/" . reset($licences))) . "\n```\n\n";
        }
    }

    return $text;
}
