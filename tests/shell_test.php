<?php
// shell_exec: the tool that runs arbitrary commands, and the one with no test
// until now.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Project\PathOutsideProjectException;
use App\Project\ProjectPathResolver;
use App\Tool\ContainerInspector;
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

$project = sys_get_temp_dir() . '/sherpa-shell-' . bin2hex(random_bytes(4));
mkdir($project . '/src/Deep', 0777, true);
file_put_contents($project . '/marqueur.txt', "un\ndeux\n");

$paths = new ProjectPathResolver();
$paths->setRoot($project);

$tool = new ShellExecTool($paths);

// ---- running things --------------------------------------------------------
check('a command runs and its output comes back', trim($tool('echo bonjour')) === 'bonjour', $tool('echo bonjour'));
check('stderr is returned too', str_contains($tool('echo oups >&2'), 'oups'), $tool('echo oups >&2'));

// The process starts in the project, not wherever PHP happened to be.
check('it runs in the project root', trim($tool('pwd')) === $project, $tool('pwd'));
check('a relative cwd is resolved inside the project', trim($tool('pwd', 'src/Deep')) === $project . '/src/Deep', $tool('pwd', 'src/Deep'));

// ---- the confinement -------------------------------------------------------
// The command can of course escape once running — that is what the confirmation
// is for — but the model may not silently start it somewhere else.
$threw = false;
try { $tool('pwd', '../../../etc'); } catch (PathOutsideProjectException) { $threw = true; }
check('a working directory outside the project is refused', $threw);

$threw = false;
try { $tool('pwd', '/etc'); } catch (PathOutsideProjectException) { $threw = true; }
check('an absolute path outside the project is refused too', $threw);

// ---- what the model is told ------------------------------------------------
check('a silent success says so', str_contains($tool('true'), 'sans sortie'), $tool('true'));
check('a silent failure names its exit code', str_contains($tool('exit 3'), 'code 3'), $tool('exit 3'));

// A failing command WITH output used to be indistinguishable from a successful
// one: a test report full of failures read as a job done.
$failed = $tool('echo "3 tests, 2 failures"; exit 1');
check('a failing command with output is marked as failing', str_contains($failed, 'code de sortie 1'), $failed);
check('and its output is still there', str_contains($failed, '2 failures'), $failed);
check('a succeeding command carries no exit-code noise', !str_contains($tool('echo ok'), 'code de sortie'), $tool('echo ok'));

// ---- output has to fit in a context window ---------------------------------
$many = $tool('seq 1 500');
check('long output is cut to 100 lines', substr_count($many, "\n") <= 101, (string) substr_count($many, "\n"));
check('and says that it was cut', str_contains($many, 'tronqué à 100 lignes'), substr($many, -120));

// One line of a megabyte passes a line count and blows the window anyway.
$huge = $tool('head -c 400000 /dev/zero | tr "\\0" "x"');
check('a single enormous line is cut by size', strlen($huge) < 30000, (string) strlen($huge));
check('and says that too', str_contains($huge, 'tronqué à'), substr($huge, -120));

// ---- a command that never finishes -----------------------------------------
// Whatever it printed before the deadline is usually the answer.
$slow = new ShellExecTool($paths, timeout: 2);
$started = microtime(true);
$result = $slow('echo "test 47 en cours"; sleep 30');
$waited = microtime(true) - $started;

check('a hanging command is stopped', $waited < 10, sprintf('%.1fs', $waited));
check('and said to have been stopped', str_contains($result, 'interrompue'), $result);
check('while keeping what it had already printed', str_contains($result, 'test 47'), $result);

// ---- Docker ----------------------------------------------------------------
// A project is mounted somewhere else inside its container. The host path used
// to be handed to `docker exec -w`, and nothing is there: every command failed
// before it started, with an OCI runtime error.

final class FakeInspector extends ContainerInspector
{
    public function __construct(private readonly ?array $result) {}

    public function inspect(string $container): ?array
    {
        return $this->result;
    }
}

$nextapi = new FakeInspector([
    'workdir' => '/srv/app',
    'mounts'  => [
        ['source' => '/home/dev', 'destination' => '/home'],
        ['source' => $project, 'destination' => '/srv/app'],
    ],
]);

$dockerised = new ShellExecTool($paths, $nextapi);
$dockerised->setDockerContainer('nextapi-app-1');

$described = $dockerised->describe('composer install');
check('the container path replaces the host path', str_contains($described, 'cwd : /srv/app'), $described);
check('and the host path is nowhere in it', !str_contains($described, $project), $described);
check('the container is named', str_contains($described, 'nextapi-app-1'), $described);

$sub = $dockerised->describe('phpunit', 'src/Deep');
check('a subdirectory is translated with it', str_contains($sub, 'cwd : /srv/app/src/Deep'), $sub);

// The longest matching mount wins: /home would otherwise swallow a project
// mounted deeper inside it.
$overlapping = new FakeInspector([
    'workdir' => '/app',
    'mounts'  => [
        ['source' => '/', 'destination' => '/hostfs'],
        ['source' => $project, 'destination' => '/srv/app'],
    ],
]);
$tool2 = new ShellExecTool($paths, $overlapping);
$tool2->setDockerContainer('c');
check('the deepest mount wins over a broader one', str_contains($tool2->describe('ls'), 'cwd : /srv/app'), $tool2->describe('ls'));

// Not mounted at all: the container's own working directory beats a path known
// to be wrong.
$unmounted = new FakeInspector(['workdir' => '/opt/app', 'mounts' => [['source' => '/ailleurs', 'destination' => '/x']]]);
$tool3 = new ShellExecTool($paths, $unmounted);
$tool3->setDockerContainer('c');
check('an unmounted project falls back to the container workdir', str_contains($tool3->describe('ls'), 'cwd : /opt/app'), $tool3->describe('ls'));

$nowhere = new FakeInspector(['workdir' => null, 'mounts' => []]);
$tool4 = new ShellExecTool($paths, $nowhere);
$tool4->setDockerContainer('c');
check('with no workdir either, it says so rather than inventing one', str_contains($tool4->describe('ls'), 'par défaut'), $tool4->describe('ls'));

// Docker unreachable: a legible sentence, not a Process exception about a
// missing binary or an OCI runtime error.
$broken = new ShellExecTool($paths, new FakeInspector(null));
$broken->setDockerContainer('absent-app-1');
$message = $broken('echo bonjour');
check('an unreachable container is explained, not thrown', str_contains($message, 'absent-app-1') && str_contains($message, 'docker'), $message);
check('and nothing was run on the host instead', !str_contains($message, 'bonjour'), $message);

// ---- the path translator on its own ----------------------------------------
$inspector = new ContainerInspector();
$table = ['workdir' => '/srv/app', 'mounts' => [['source' => '/home/x/projet', 'destination' => '/srv/app']]];

check('an exact mount translates', $inspector->translate($table, '/home/x/projet') === '/srv/app');
check('a trailing slash changes nothing', $inspector->translate($table, '/home/x/projet/') === '/srv/app');
check('a path below the mount keeps its tail', $inspector->translate($table, '/home/x/projet/src/A') === '/srv/app/src/A');
// "projet-old" starts with "projet"; a raw prefix match would send commands
// into the wrong container directory.
check('a sibling sharing a prefix does not match', $inspector->translate($table, '/home/x/projet-old') === null);
check('an unrelated path does not match', $inspector->translate($table, '/etc') === null);

exec('rm -rf ' . escapeshellarg($project));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
