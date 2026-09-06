<?php
// The project is the directory Sherpa is launched from. A known one opens at
// once; a new one is asked about and held — nothing written — until the first
// message. These are the rules that replaced the project menu.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Memory\MemoryStore;
use App\Permission\ProjectPermissions;
use App\Project\DockerConfig;
use App\Project\ProjectPathResolver;
use App\Project\ProjectStore;
use App\Project\StackDetector;
use App\Project\WorkingProject;
use App\Session\SessionStore;
use App\Tool\ShellExecTool;
use Symfony\Component\Process\Process;

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

$realHome = $_SERVER['HOME'] ?? null;
$root = sys_get_temp_dir() . '/sherpa-working-' . bin2hex(random_bytes(4));
$home = $root . '/home';
mkdir($home . '/.config/sherpa', 0777, true);
$_SERVER['HOME'] = $home;

// ---- where Sherpa refuses to start a project -------------------------------
// A project confines the file tools to its directory. From home or from /,
// that confinement would be everything.
check('the home directory is refused', str_contains((string) WorkingProject::refusal($home, $home), 'home directory'));
check('so is the root of the disk', str_contains((string) WorkingProject::refusal('/', $home), 'root of the disk'));
check('and a directory that does not exist', WorkingProject::refusal($root . '/nulle-part', $home) !== null);
mkdir($home . '/atelier', 0777, true);
check('an ordinary directory is fine', WorkingProject::refusal($home . '/atelier', $home) === null);

// ---- a new directory: held, nothing written ---------------------------------
$projects = new ProjectStore(new StackDetector());
$memory = new MemoryStore();
$sessions = new SessionStore();
$paths = new ProjectPathResolver();
$shell = new ShellExecTool($paths);
$working = new WorkingProject($projects, $memory, new ProjectPermissions($projects), $sessions, $paths, $shell);

$dir = $home . '/atelier';
$config = $home . '/.config/sherpa';
$working->hold($projects->draft($dir, 'atelier', new DockerConfig(enabled: true, container: 'atelier-app-1')));

check('a held project is not saved', !$working->isSaved());
check('and nothing is written: no projects.yaml, no project directory',
    !is_file($config . '/projects.yaml') && !is_dir($config . '/projects'),
    implode(', ', glob($config . '/*') ?: []));
check('yet the file tools are already confined to it', $paths->root() === ProjectStore::canonical($dir), $paths->root());
check('and shell_exec already knows its container', (new ReflectionProperty($shell, 'dockerContainer'))->getValue($shell) === 'atelier-app-1');

// Memory works while held — the prompt builder reads it — but in RAM.
$memory->remember('trial', 'in RAM');
check('a held project\'s memory lives in RAM, not in a file', !is_dir($config . '/projects'));

// ---- the first message -------------------------------------------------------
check('save() makes it real', $working->save() === true && $working->isSaved());
check('and says so only once', $working->save() === false);

$reloaded = new ProjectStore(new StackDetector());
check('it is now found by its directory', $reloaded->forDirectory($dir)?->name === 'atelier');
check('and from a subdirectory too', (mkdir($dir . '/src') || true) && $reloaded->forDirectory($dir . '/src')?->name === 'atelier');
check('its memory is a real file now', is_file($working->project()->memoryDb), $working->project()->memoryDb);

$memory->remember('lasting', 'written to disk');
$check = new MemoryStore();
$check->open($working->project()->memoryDb);
check('and what is remembered from here on is kept', $check->recall('lasting') !== []);

// ---- a known directory opens at once ---------------------------------------
$again = new WorkingProject($reloaded, new MemoryStore(), new ProjectPermissions($reloaded), new SessionStore(), new ProjectPathResolver(), new ShellExecTool());
$again->open($reloaded->forDirectory($dir));
check('a known directory opens saved, with no question', $again->isSaved() && $again->project()->name === 'atelier');

// ---- the questions, answered as a person would ------------------------------
// Run in a real process with the answers on stdin: the questions read input,
// and reading this test's own stdin would hang or answer at random.
function answer(string $dir, string $input, string $home): array
{
    $process = new Process([PHP_BINARY, __DIR__ . '/fixtures/ask_project.php', $dir], env: ['HOME' => $home, 'PATH' => '/usr/bin:/bin'], input: $input, timeout: 20);
    $process->run();

    return json_decode($process->getOutput(), true) ?? ['erreur' => $process->getErrorOutput() . $process->getOutput()];
}

mkdir($home . '/boutique', 0777, true);
$draft = answer($home . '/boutique', "\n\n", $home);
check('Enter all the way through is a valid setup: the directory\'s name, no Docker',
    ($draft['name'] ?? null) === 'boutique' && ($draft['docker'] ?? null) === false, json_encode($draft));

$draft = answer($home . '/boutique', "Ma Boutique\no\nboutique-php-1\nboutique-db-1\n", $home);
check('typed answers are taken as typed',
    ($draft['name'] ?? null) === 'Ma Boutique' && ($draft['container'] ?? null) === 'boutique-php-1' && ($draft['db'] ?? null) === 'boutique-db-1',
    json_encode($draft));

// Docker with no container named is no setting at all — and a question that
// repeated until answered would never end on Ctrl+D.
$draft = answer($home . '/boutique', "\no\n\n", $home);
check('Docker without a container falls back to no Docker, instead of asking forever',
    ($draft['docker'] ?? null) === false, json_encode($draft));

file_put_contents($home . '/boutique/compose.yaml', "services: {}\n");
$draft = answer($home . '/boutique', "\n\nboutique-app-1\n\n", $home);
check('a compose file makes Docker the default answer',
    ($draft['docker'] ?? null) === true && ($draft['container'] ?? null) === 'boutique-app-1', json_encode($draft));

check('and answering never wrote a project', (new ProjectStore(new StackDetector()))->forDirectory($home . '/boutique') === null);

// ---- forgetting a project -----------------------------------------------------
// Before the menu went away, deleting was the "d" key. Now it is /project
// forget, and it has to take everything Sherpa kept about the directory with it.
$held = new WorkingProject($projects, new MemoryStore(), new ProjectPermissions($projects), new SessionStore(), new ProjectPathResolver(), new ShellExecTool());
mkdir($home . '/brouillon', 0777, true);
$held->hold($projects->draft($home . '/brouillon', 'brouillon', new DockerConfig(enabled: false)));
check('a project never saved has nothing to forget', $held->forget() === false && !$held->isForgotten());

$slug = $working->project()->slug;
$own = dirname($working->project()->memoryDb);
$sessions->save([['role' => 'user', 'content' => 'une conversation']], [], 'api', 'm');
check('the project to forget has a memory and a conversation on disk',
    is_file($working->project()->memoryDb) && glob($own . '/sessions/*.json') !== []);

check('forget() forgets it', $working->forget() === true && $working->isForgotten());
check('its entry is gone', (new ProjectStore(new StackDetector()))->forDirectory($dir) === null);
check('its memory and its conversations with it', !is_dir($own), $own);
check('the directory itself is untouched', is_dir($dir) && is_dir($dir . '/src'));

// The session still runs until the command stops it, and what runs at the end
// of a session — saving the conversation, writing facts — must not put back
// one file at a time what was just deleted.
check('saving it again does nothing', $working->save() === false);
check('a conversation saved afterwards goes nowhere',
    $sessions->save([['role' => 'user', 'content' => 'after']], [], 'api', 'm') === false && !is_dir($own));
$memory->remember('after', 'the fact');
check('nor does a fact remembered afterwards', !is_dir($own));

// ---- the question before it --------------------------------------------------
function confirmForget(string $input): array
{
    $process = new Process([PHP_BINARY, __DIR__ . '/fixtures/confirm_forget.php'], input: $input, timeout: 20);
    $process->run();

    return json_decode($process->getOutput(), true) ?? ['erreur' => $process->getErrorOutput() . $process->getOutput()];
}

$asked = confirmForget("\n");
check('Enter alone keeps everything', ($asked['confirmed'] ?? null) === false, json_encode($asked));
check('the question says how much would go', str_contains($asked['shown'] ?? '', '12 remembered facts') && str_contains($asked['shown'] ?? '', '3 conversations'), $asked['shown'] ?? '');
check('and that the directory stays', str_contains($asked['shown'] ?? '', 'is not touched'), $asked['shown'] ?? '');
check('"n" keeps everything too', (confirmForget("n\n")['confirmed'] ?? null) === false);
check('only a yes forgets', (confirmForget("o\n")['confirmed'] ?? null) === true && (confirmForget("oui\n")['confirmed'] ?? null) === true);

if ($realHome !== null) {
    $_SERVER['HOME'] = $realHome;
}
exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
