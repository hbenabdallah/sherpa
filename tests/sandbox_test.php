<?php
// Verification that filesystem tools cannot escape the project root.
//
// file_read runs at AUTO permission — no confirmation prompt stands between a
// model and whatever it asks for — and Sherpa runs with the user's home mounted.
// Escapes here are silent and total, so these cases are deliberately adversarial.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\Tool\ToolCall;
use App\Agent\Tool\Toolbox;
use App\Project\PathOutsideProjectException;
use App\Project\ProjectPathResolver;
use App\Tool\FilePatchTool;
use App\Tool\FileReadTool;
use App\Tool\FileWriteTool;
use App\Tool\ProjectGrepTool;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo "        ", substr($detail, 0, 220), "\n";
    }
}

/** Assert a path is refused. */
function blocked(ProjectPathResolver $r, string $path): bool
{
    try {
        $r->resolve($path);
        return false;
    } catch (PathOutsideProjectException) {
        return true;
    }
}

/** Assert a path is allowed, returning the resolved value. */
function allowed(ProjectPathResolver $r, string $path): ?string
{
    try {
        return $r->resolve($path);
    } catch (PathOutsideProjectException) {
        return null;
    }
}

// ---- fixture --------------------------------------------------------------
$base = sys_get_temp_dir() . '/sherpa-sandbox-' . bin2hex(random_bytes(3));
$project = $base . '/project';
$outside = $base . '/outside';
mkdir($project . '/src/Deep', 0777, true);
mkdir($outside, 0777, true);
file_put_contents($project . '/src/Inside.php', "<?php // inside\n");
file_put_contents($outside . '/secret.txt', "TOP_SECRET_VALUE\n");

$r = new ProjectPathResolver();
$r->setRoot($project);

// ---- paths that must be allowed -------------------------------------------
check('relative path inside is allowed', allowed($r, 'src/Inside.php') === $project . '/src/Inside.php');
check('absolute path inside is allowed', allowed($r, $project . '/src/Inside.php') !== null);
check('the root itself is allowed', allowed($r, '.') === $project);
check('"./" segments normalise', allowed($r, 'src/./Inside.php') === $project . '/src/Inside.php');
check('interior ".." that stays inside is allowed', allowed($r, 'src/Deep/../Inside.php') === $project . '/src/Inside.php');
check(
    'file that does not exist yet is allowed (file_write case)',
    allowed($r, 'src/Brand/New/File.php') === $project . '/src/Brand/New/File.php',
);

// ---- paths that must be blocked -------------------------------------------
check('absolute path outside is blocked', blocked($r, '/etc/passwd'));
check('the real /etc/passwd is blocked', blocked($r, '/etc/passwd'));
check('sibling directory is blocked', blocked($r, $outside . '/secret.txt'));
check('simple ".." escape is blocked', blocked($r, '../outside/secret.txt'));
check('deep ".." escape is blocked', blocked($r, 'src/Deep/../../../outside/secret.txt'));
check('excessive ".." past filesystem root is blocked', blocked($r, '../../../../../../../etc/passwd'));
check('home-dir SSH key is blocked', blocked($r, getenv('HOME') . '/.ssh/id_rsa'));
check('non-existent path outside is blocked', blocked($r, '/nonexistent/elsewhere/x.txt'));
check('prefix-collision sibling is blocked', blocked($r, $project . '-evil/x.txt'));

// ---- symlink escape -------------------------------------------------------
symlink($outside, $project . '/escape_hatch');
check('symlink to an outside directory is blocked', blocked($r, 'escape_hatch/secret.txt'));

symlink($outside . '/secret.txt', $project . '/escape_file.txt');
check('symlink to an outside file is blocked', blocked($r, 'escape_file.txt'));

// ---- project root that is itself a symlink --------------------------------
$linkRoot = $base . '/link-to-project';
symlink($project, $linkRoot);
$r2 = new ProjectPathResolver();
$r2->setRoot($linkRoot);
check('symlinked project root still resolves its own files', allowed($r2, 'src/Inside.php') !== null);
check('symlinked project root still blocks escapes', blocked($r2, '../outside/secret.txt'));

// ---- the tools, exercised through the real execution path -----------------
// Toolbox::execute() is what the agent loop calls; it converts a refusal into
// an error ToolResult so the model can correct itself instead of the run dying.
$read = new FileReadTool($r);
$write = new FileWriteTool($r);
$patch = new FilePatchTool($r);
$grep = new ProjectGrepTool($r);

$toolbox = new Toolbox([$read, $write, $patch, $grep]);
$exec = fn(string $name, array $args) => $toolbox->execute(new ToolCall('id', $name, $args));

$res = $exec('file_read', ['path' => '/etc/passwd']);
check('file_read refuses /etc/passwd', $res->isError && !str_contains($res->content, 'root:'), $res->content);
check('the refusal explains itself to the model', str_contains($res->content, 'outside the project'), $res->content);

$res = $exec('file_read', ['path' => '../outside/secret.txt']);
check('file_read refuses a traversal', !str_contains($res->content, 'TOP_SECRET_VALUE'), $res->content);

$res = $exec('file_read', ['path' => 'src/Inside.php']);
check('file_read still works inside the project', str_contains($res->content, 'inside'), $res->content);

$target = $outside . '/pwned.txt';
$exec('file_write', ['path' => $target, 'content' => 'pwned']);
check('file_write did not create a file outside', !file_exists($target));

$res = $exec('file_write', ['path' => 'src/Created.php', 'content' => "<?php\n"]);
check('file_write still works inside the project', file_exists($project . '/src/Created.php'), $res->content);

check('file_write preview reports refusal instead of throwing', str_starts_with($write->getDiff('/etc/hosts', 'x'), '!! '));
check('file_patch preview reports refusal instead of throwing', str_starts_with($patch->getDiff('../outside/secret.txt', 'a', 'b'), '!! '));

$res = $exec('project_grep', ['pattern' => 'TOP_SECRET_VALUE', 'path' => '../outside']);
check('project_grep refuses an outside subdirectory', !str_contains($res->content, 'TOP_SECRET_VALUE'), $res->content);

$res = $exec('project_grep', ['pattern' => 'inside']);
check('project_grep still works inside the project', str_contains($res->content, 'Inside.php'), $res->content);

exec('rm -rf ' . escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
