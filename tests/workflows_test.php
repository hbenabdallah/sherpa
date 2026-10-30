<?php

// The GitHub workflows, read as GitHub reads them. A line with ": " inside an
// unquoted value made tests.yml invalid YAML, and GitHub then ran none of its
// jobs — reported as one failure with nothing in it, after the push.

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr($detail, 0, 400), "\n";
    }
}

$files = glob(__DIR__ . '/../.github/workflows/*.yml') ?: [];
check('there are workflows to read', $files !== []);

foreach ($files as $file) {
    $name = basename($file);

    try {
        $workflow = Yaml::parseFile($file);
        $error = '';
    } catch (Throwable $e) {
        $workflow = null;
        $error = $e->getMessage();
    }

    check("{$name} is valid YAML", is_array($workflow), $error);

    $jobs = is_array($workflow) ? ($workflow['jobs'] ?? null) : null;
    $shaped = is_array($jobs) && $jobs !== [] && array_filter($jobs, fn($job): bool => !is_array($job)
        || (!isset($job['runs-on']) && !isset($job['uses'])) || (!isset($job['steps']) && !isset($job['uses']))) === [];
    check("{$name} has jobs, each with a runner and steps", $shaped, json_encode(is_array($jobs) ? array_keys($jobs) : $jobs));

    // A step's run is a string; a ": " read as a mapping makes it an array.
    $runs = [];
    foreach (is_array($jobs) ? $jobs : [] as $job) {
        foreach ((array) ($job['steps'] ?? []) as $step) {
            if (is_array($step) && array_key_exists('run', $step)) {
                $runs[] = $step['run'];
            }
        }
    }
    check(
        "{$name}: every run is a command, not something YAML read as a mapping",
        array_filter($runs, fn($run): bool => !is_string($run)) === [],
        json_encode($runs),
    );
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
