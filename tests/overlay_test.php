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
use App\Permission\ConfirmChoice;
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

// ---- The box is printed in the flow, not painted over the chat -------------
$frame = $overlay->frame(
    $call('shell_exec', ['command' => 'ls -la']),
    new ToolDefinition('shell_exec', 'Run a command', [], Permission::CONFIRM, $shell),
    ["$ ls -la", "+ ajouté \e[2J\e[H faux écran", str_repeat('x', 200)],
    40,
);
$lines = explode("\n", rtrim(Terminal::plain($frame), "\n"));
check('no cursor movement: it prints where the conversation is', !preg_match('/\e\[\d*;?\d*[HfABCD]/', $frame), json_encode($frame));
check('a diff cannot clear the screen from inside the box', !str_contains($frame, "\e[2J"), json_encode($frame));
check('every line of the box is the same width, long ones cut',
    count(array_unique(array_map('mb_strwidth', $lines))) === 1, implode("\n", $lines));
check('the tool is named in the title', str_contains($lines[0], 'Confirmation — shell_exec'), $lines[0]);

// ---- What was chosen stays on screen ------------------------------------------
check('allowing says it runs now', str_contains(Terminal::plain(ConfirmOverlay::verdict(ConfirmChoice::Once, 'shell_exec')), 'Allowed once — running shell_exec'));
check('the standing grant says so', str_contains(Terminal::plain(ConfirmOverlay::verdict(ConfirmChoice::Project, 'shell_exec')), 'Always allowed in this project'));
check('refusing says it does not run', str_contains(Terminal::plain(ConfirmOverlay::verdict(ConfirmChoice::Deny, 'shell_exec')), 'Refused — shell_exec does not run'));

exec('rm -rf ' . escapeshellarg($project));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
