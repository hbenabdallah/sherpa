<?php
/**
 * Minimal test runner — no PHPUnit dependency yet.
 *
 * Each tests/*_test.php is a standalone script that prints PASS/FAIL lines and
 * exits non-zero on failure. Run with: make test
 */

$files = glob(__DIR__ . '/*_test.php');
sort($files);

$failed = [];

foreach ($files as $file) {
    $name = basename($file, '.php');
    echo "\n\033[1m── {$name}\033[0m\n";

    passthru(sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $code);

    if ($code !== 0) {
        $failed[] = $name;
    }
}

echo "\n";

if ($failed !== []) {
    printf("\033[31m✗ %d suite(s) failed: %s\033[0m\n", count($failed), implode(', ', $failed));
    exit(1);
}

printf("\033[32m✓ all %d suite(s) passed\033[0m\n", count($files));
