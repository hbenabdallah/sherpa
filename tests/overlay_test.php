<?php
// Verification of ConfirmOverlay previews resolving against the project root.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\Tool\Permission;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;
use App\Project\ProjectPathResolver;
use App\Tool\FilePatchTool;
use App\Tool\FileWriteTool;
use App\Tool\ShellExecTool;
use App\TUI\ConfirmOverlay;
use App\TUI\Terminal;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $got !== '') {
        echo "        got: ", str_replace("\n", "\n             ", substr($got, 0, 300)), "\n";
    }
}

// A project root that is NOT the cwd — this is precisely what used to break.
$project = sys_get_temp_dir() . '/sherpa-overlay-' . bin2hex(random_bytes(3));
mkdir($project . '/src', 0777, true);
file_put_contents($project . '/src/Existing.php', "<?php\nclass Existing {}\n");

$paths = new ProjectPathResolver();
$paths->setRoot($project);

$write = new FileWriteTool($paths);
$patch = new FilePatchTool($paths);
$shell = new ShellExecTool($paths);

$overlay = new ConfirmOverlay(new Terminal(), $write, $patch, $shell);
$method = new ReflectionMethod($overlay, 'buildPreview');
$preview = fn(...$a) => $method->invoke($overlay, ...$a);
$def = new ToolDefinition('x', 'x', [], Permission::CONFIRM, $write);
$call = fn(string $name, array $args) => new ToolCall('id', $name, $args);

// ---- The regression: overwriting an existing file must NOT read as "new" ----
$out = $preview($call('file_write', [
    'path' => 'src/Existing.php',
    'content' => "<?php\nclass Existing { public int \$added = 1; }\n",
]), $def);
check('overwrite of existing file is not labelled "(new file)"', !str_contains($out, '(new file)'), $out);
check('overwrite preview shows removed lines', str_contains($out, '-'), $out);

// ---- A genuinely new file still reads as new -------------------------------
$out = $preview($call('file_write', [
    'path' => 'src/Brand/New.php',
    'content' => "<?php\n",
]), $def);
check('genuinely new file is labelled "(new file)"', str_contains($out, '(new file)'), $out);

// ---- file_patch warns when the search string will not apply ----------------
$out = $preview($call('file_patch', [
    'path' => 'src/Existing.php', 'search' => 'NOT PRESENT ANYWHERE', 'replace' => 'x',
]), $def);
check('unmatched patch is flagged before approval', str_contains($out, 'will fail'), $out);

$out = $preview($call('file_patch', [
    'path' => 'src/Existing.php', 'search' => 'class Existing {}', 'replace' => 'class Existing { }',
]), $def);
check('valid patch shows no warning', !str_contains($out, '!!'), $out);
check('valid patch shows the replacement', str_contains($out, '+ class Existing { }'), $out);

// ---- shell_exec shows the real working directory ---------------------------
$out = $preview($call('shell_exec', ['command' => 'php -v']), $def);
check('shell preview shows project cwd', str_contains($out, $project), $out);
check('shell preview shows the command', str_contains($out, '$ php -v'), $out);

// ---- shell_exec reveals Docker wrapping ------------------------------------
$shell->setDockerContainer('my-php-container');
$out = $preview($call('shell_exec', ['command' => 'php -v']), $def);
check('docker wrapping is disclosed', str_contains($out, 'my-php-container'), $out);

exec('rm -rf ' . escapeshellarg($project));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
