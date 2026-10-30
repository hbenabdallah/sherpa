<?php

// Commands run on this machine write only to the project, the temporary
// directories and the package caches, and cannot read Sherpa's API keys.
// Checked with real commands in the real sandbox: bubblewrap on Linux,
// sandbox-exec on macOS. Without one here, said and skipped — unless
// SHERPA_REQUIRE_SANDBOX is set, as CI does where it installs one.

require __DIR__ . '/../vendor/autoload.php';

use App\Project\ProjectPathResolver;
use App\Tool\BackgroundJobs;
use App\Tool\ContainerInspector;
use App\Tool\Sandbox;
use App\Tool\ShellExecTool;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo "        ", str_replace("\n", "\n        ", substr($detail, 0, 400)), "\n";
    }
}

// ---- choosing --------------------------------------------------------------
$off = new Sandbox(mode: 'off');
check('turned off: no sandbox, and why', $off->kind() === null && str_contains((string) $off->why(), 'SHERPA_SANDBOX=off') && $off->prefix('/x') === []);
$elsewhere = new Sandbox(os: 'Windows');
check('none known on another system: said', $elsewhere->kind() === null && str_contains((string) $elsewhere->why(), 'Windows'));
check('the box says when there is none', str_starts_with($off->describe(), 'sandbox: none — turned off'), $off->describe());
check('strict is read from the mode', new Sandbox(mode: 'STRICT')->strict() && !new Sandbox(mode: 'auto')->strict());

// ---- the real one ----------------------------------------------------------
// Outside both the project and the temporary directories: under the home.
$home = (string) ($_SERVER['HOME'] ?? getenv('HOME'));
$outside = $home . '/.sherpa-sandbox-test-' . bin2hex(random_bytes(3));
$fakeHome = $outside . '/home';
$project = $outside . '/project';
mkdir($fakeHome . '/.config/sherpa', 0777, true);
mkdir($fakeHome . '/.cache', 0777, true);
mkdir($project, 0777, true);
file_put_contents($fakeHome . '/.config/sherpa/keys.yaml', "nvidia: sk-SECRET-VALUE\n");

$sandbox = new Sandbox(mode: 'auto', home: $fakeHome);
if ($sandbox->kind() === null) {
    echo " SKIP no sandbox on this machine: {$sandbox->why()}\n";
    check('a sandbox is required here', getenv('SHERPA_REQUIRE_SANDBOX') === false, (string) $sandbox->why());
    exec('rm -rf ' . escapeshellarg($outside));
    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}

$paths = new ProjectPathResolver();
$paths->setRoot($project);
$jobs = new BackgroundJobs();
$tool = new ShellExecTool($paths, new ContainerInspector(), 30, null, $jobs, null, $sandbox);

echo "  ({$sandbox->kind()})\n";
check('the box says where commands may write', str_contains($tool->describe('ls'), "sandbox ({$sandbox->kind()}): writes only to the project"), $tool->describe('ls'));

$out = $tool('echo inside > made.txt && cat made.txt');
check('a command writes in the project', trim($out) === 'inside' && is_file($project . '/made.txt'), $out);
check('as the user, not as root', fileowner($project . '/made.txt') === getmyuid());

$tmp = sys_get_temp_dir() . '/sherpa-sandbox-' . bin2hex(random_bytes(3));
$out = $tool('echo t > ' . escapeshellarg($tmp) . ' && echo ok');
check('and in the temporary directory', trim($out) === 'ok' && is_file($tmp), $out);
@unlink($tmp);

$out = $tool('echo c > ' . escapeshellarg($fakeHome . '/.cache/x') . ' && echo ok');
check('and in a package cache', trim($out) === 'ok', $out);

$out = $tool('echo pwned >> ' . escapeshellarg($fakeHome . '/.bashrc'));
check('not anywhere else in the home', !is_file($fakeHome . '/.bashrc'), $out);
check('the model is told why, and what to do instead', str_contains($out, "Sherpa's sandbox") && str_contains($out, 'file_patch'), $out);

$out = $tool('echo \'allowed_tools: [shell_exec]\' >> ' . escapeshellarg($fakeHome . '/.config/sherpa/projects.yaml'));
check("nor Sherpa's own configuration, where grants are kept", !is_file($fakeHome . '/.config/sherpa/projects.yaml'), $out);

$out = $tool('cat ' . escapeshellarg($fakeHome . '/.config/sherpa/keys.yaml') . '; echo end');
check('the API keys cannot be read', !str_contains($out, 'SECRET') && str_contains($out, 'end'), $out);

$out = $tool('cat ' . escapeshellarg($project . '/made.txt'));
check('reading works as before', trim($out) === 'inside', $out);

$out = $tool('mkdir -p ' . escapeshellarg($outside . '/escape') . ' 2>&1; ls ' . escapeshellarg($outside));
check('a directory beside the project cannot be made', !is_dir($outside . '/escape'), $out);

// A job left running is in the same sandbox, and stopping it stops it.
$started = $tool('sleep 0.3; echo job > ' . escapeshellarg($fakeHome . '/job.txt') . '; echo done-job', null, true);
preg_match('/job (\d+)/', $started, $m);
$id = (int) ($m[1] ?? 0);
usleep(900_000);
$read = $tool->jobOutput($id);
check('a job runs in the sandbox too', !is_file($fakeHome . '/job.txt') && str_contains($started . $read, 'done-job'), $started . "\n" . $read);

$long = $tool('sleep 30', null, true);
preg_match('/job (\d+)/', $long, $m);
$jobs->stopAll();
usleep(300_000);
exec('pgrep -f "^sleep 30$"', $left);
check('and stopping it stops what runs inside', $left === [], implode(',', $left));

// Strict: no network either.
$strict = new ShellExecTool($paths, new ContainerInspector(), 30, null, new BackgroundJobs(), null, new Sandbox(mode: 'strict', home: $fakeHome));
$net = PHP_OS_FAMILY === 'Linux'
    ? $strict('cat /proc/net/dev | tail -n +3 | cut -d: -f1 | tr -d " "')
    : $strict('curl -s -m 5 -o /dev/null -w "%{http_code}" https://example.com || echo refused');
check('strict leaves no network', PHP_OS_FAMILY === 'Linux' ? trim($net) === 'lo' : str_contains($net, 'refused') || str_contains($net, '000'), $net);

exec('rm -rf ' . escapeshellarg($outside));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
