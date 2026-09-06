<?php
// Background jobs: left running, read as they go, stopped with what they started.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Project\ProjectPathResolver;
use App\Tool\BackgroundJobs;
use App\Tool\ContainerInspector;
use App\Tool\JobOutputTool;
use App\Tool\JobStopTool;
use App\Tool\ShellExecTool;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr($detail, 0, 300), "\n";
    }
}

$root = sys_get_temp_dir() . '/sherpa-jobs-' . bin2hex(random_bytes(4));
mkdir($root);
$paths = new ProjectPathResolver();
$paths->setRoot($root);
$jobs = new BackgroundJobs();
$shell = new ShellExecTool($paths, new ContainerInspector(), 300, null, $jobs);
$output = new JobOutputTool($shell);
$stop = new JobStopTool($jobs);
// Alive, not a zombie: in a container where the test is pid 1, nobody reaps a
// killed orphan, and a zombie still answers signal 0.
$alive = function (int $pid): bool {
    if ($pid <= 0 || !posix_kill($pid, 0)) {
        return false;
    }
    $stat = @file_get_contents("/proc/{$pid}/stat");

    return $stat === false || preg_match('/\) Z /', $stat) !== 1;
};

// A command that ends within the first look is reported like any command.
$out = $shell('echo built; exit 3', background: true);
check('a job that ends at once says so, with its exit code and output',
    str_contains($out, 'already ended, exit code 3') && str_contains($out, 'built'), $out);

// A server: still running after the first look, its first line shown.
$started = microtime(true);
$out = $shell('echo "listening on :8000"; sleep 30', background: true);
check('a long command is left running, with a job id', str_contains($out, 'Started in the background as job 2'), $out);
check('and its first output shown', str_contains($out, 'listening on :8000'), $out);
check('without waiting for it to end', microtime(true) - $started < 6, sprintf('%.1f s', microtime(true) - $started));
check('nothing new is said as such', str_contains($output(2), 'running for') && str_contains($output(2), 'nothing new printed'), $output(2));

// Output read as it comes, and only what is new.
$shell('echo one; sleep 4; echo two; sleep 30', background: true);
sleep(2);
$read = $output(3);
check('job_output gives what was printed since the last read', str_contains($read, 'two') && !str_contains($read, 'one'), $read);

// What the command started goes with it: `npm run dev` is a tree.
$out = $shell('sleep 60 & echo "child $!"; wait', background: true);
$child = preg_match('/child (\d+)/', $out, $m) === 1 ? (int) $m[1] : 0;
check('a job that starts a child process', $alive($child), $out);
check('job_stop stops it', str_contains($stop(4), 'job 4 stopped'));
usleep(300_000);
check('and the child it started, too', !$alive($child), "child {$child} still alive");
check('stopping it again is harmless', str_contains($stop(4), 'already ended'));

// Without setsid — macOS — perl makes the group, and the child still goes.
$noSetsid = $root . '/bin';
mkdir($noSetsid);
foreach (['bash', 'perl', 'sleep'] as $tool) {
    $found = trim((string) shell_exec('command -v ' . $tool));
    if ($found !== '') {
        symlink($found, "{$noSetsid}/{$tool}");
    }
}
if (is_link("{$noSetsid}/perl")) {
    $savedPath = getenv('PATH');
    putenv("PATH={$noSetsid}");
    $out = $shell('sleep 60 & echo "child $!"; wait', background: true);
    putenv("PATH={$savedPath}");
    $child = preg_match('/child (\d+)/', $out, $m) === 1 ? (int) $m[1] : 0;
    $id = preg_match('/job (\d+)/', $out, $m) === 1 ? (int) $m[1] : 0;
    $stop($id);
    usleep(300_000);
    check('without setsid, perl gives the job its group, and stopping reaches the child', $child > 0 && !$alive($child), $out);
} else {
    echo " SKIP no perl here to stand in for setsid\n";
}

$threw = false;
try { $output(99); } catch (RuntimeException $e) { $threw = str_contains($e->getMessage(), 'no job 99'); }
check('an unknown id is an error the model can act on', $threw);

$listed = array_column($jobs->all(), 'command');
check('/jobs has every job, with its command', count($listed) >= 4 && str_contains($listed[1], 'listening on :8000'), json_encode($listed));

// Sherpa exits: nothing left running behind it.
$pids = array_filter(array_map(fn(array $j) => $j['running'] ? $j['id'] : null, $jobs->all()));
$jobs->stopAll();
check('stopAll leaves nothing running', array_filter($jobs->all(), fn(array $j) => $j['running']) === [], json_encode($jobs->all()));

// ---- permissions ----------------------------------------------------------------
$toolbox = new Toolbox([$shell, $output, $stop]);
check('starting a command still asks first', $toolbox->find('shell_exec')?->permission === Permission::CONFIRM);
check('reading or stopping a job Sherpa started does not',
    $toolbox->find('job_output')?->permission === Permission::AUTO && $toolbox->find('job_stop')?->permission === Permission::AUTO);
check('shell_exec says background is an option', isset($toolbox->find('shell_exec')?->parameters['background']));

exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
