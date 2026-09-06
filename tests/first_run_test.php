<?php
// The first run asks for the key even when SHERPA_API_KEY is set: that
// variable may hold another provider's key, and must not reach this one
// unless the person says so.

require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Process\Process;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr($detail, 0, 500), "\n";
    }
}

/** @return array{0: string, 1: string} what it printed, and the keys file */
function firstRun(string $answers, ?string $envKey): array
{
    $dir = sys_get_temp_dir() . '/sherpa-first-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $env = ['SHERPA_API_KEY' => $envKey ?? false, 'HOME' => $dir];
    $process = new Process([PHP_BINARY, __DIR__ . '/fixtures/first_run.php', $dir], env: $env, input: $answers, timeout: 30);
    $process->run();
    $keys = (string) @file_get_contents($dir . '/keys.yaml');
    exec('rm -rf ' . escapeshellarg($dir));

    return [$process->getOutput() . $process->getErrorOutput(), $keys];
}

[$out, $keys] = firstRun("https://api.groq.test/openai/v1\n\n", 'key-of-another-provider');
check('with SHERPA_API_KEY set, the key is still asked for', str_contains($out, 'API key (hidden'), $out);
check('and the question says Enter uses that variable', str_contains($out, 'Enter to use the one in SHERPA_API_KEY'), $out);
check('Enter then uses it, knowingly', str_contains($out, 'SENT: Bearer key-of-another-provider'), $out);

[$out, $keys] = firstRun("https://api.groq.test/openai/v1\ngsk-typed\n", 'key-of-another-provider');
check('a key typed is the one sent, not the variable', str_contains($out, 'Bearer gsk-typed') && !str_contains($out, 'key-of-another-provider'), $out);
check('and it is saved for that provider', str_contains($keys, 'gsk-typed'), $keys);

[$out] = firstRun("https://api.groq.test/openai/v1\n\n", null);
check('without the variable, the question is the usual one', str_contains($out, 'Enter if it needs none'), $out);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
