<?php

declare(strict_types=1);

namespace App\Tests\FrontendTest;

use App\Agent\Tool\Permission;
use App\Permission\Offer;
use App\Tool\Edit\ProposedEdit;
use App\Tool\FileReadTool;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\LazyCommand;

// The seam between the agent and whoever it is in front of: the terminal by
// default, an editor over ACP once the frontend is switched. Everything that
// shows a turn or asks a question must go through the one switch, or an
// editor session would see half a turn and wait on a keyboard nobody has.

require __DIR__ . '/../vendor/autoload.php';

use App\Agent\AgentOutput;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;
use App\Kernel;
use App\Permission\Approver;
use App\Permission\ConfirmChoice;
use App\Project\ProjectPathResolver;
use App\Runtime\Frontend;
use App\Runtime\Interrupt;
use App\Tool\DelegateTool;
use App\Tool\Edit\EditPreview;
use App\Tool\Edit\Files;
use App\Tool\FilePatchTool;
use App\Tool\FileWriteTool;
use App\Tool\PlanReadyTool;
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

function property(object $object, string $name): mixed
{
    return new ReflectionProperty($object, $name)->getValue($object);
}

/** Records what it is told, in order. */
final class Recorder implements AgentOutput, Approver
{
    /** @var list<string> */
    public array $seen = [];

    public function beginAssistantMessage(): void
    {
        $this->seen[] = 'begin';
    }

    public function appendToken(string $token): void
    {
        $this->seen[] = "token:{$token}";
    }

    public function flushBuffer(bool $wasToolCall = false): void
    {
        $this->seen[] = 'flush';
    }

    public function showWaiting(bool $reasoning = false, ?string $why = null): void {}

    public function showRunning(string $toolName, float $seconds): void {}

    public function showToolCall(string $toolName, array $args = [], ?string $callId = null): void
    {
        $this->seen[] = "call:{$toolName}:{$callId}";
    }

    public function showToolResult(string $toolName, string $result, bool $isError = false, ?string $callId = null): void
    {
        $this->seen[] = "result:{$toolName}:{$callId}";
    }

    public function showInfo(string $message): void
    {
        $this->seen[] = "info:{$message}";
    }

    public function showError(string $message): void
    {
        $this->seen[] = "error:{$message}";
    }

    public function forSubagent(): AgentOutput
    {
        return $this;
    }

    public function confirm(ToolCall $call, ToolDefinition $def, Offer $offer): ConfirmChoice
    {
        $this->seen[] = "confirm:{$call->id}";

        return ConfirmChoice::Session;
    }

    public function approvePlan(string $plan): bool
    {
        $this->seen[] = "plan:{$plan}";

        return false;
    }
}

// ---- one switch, everywhere --------------------------------------------------------
$kernel = new Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();

$command = new Application($kernel)->find('sherpa');
if ($command instanceof LazyCommand) {
    $command = $command->getCommand();
}
$agent = property($command, 'agentLoop');
$frontend = property($agent, 'chat');
check('the agent loop shows its turns through the frontend switch', $frontend instanceof Frontend, get_debug_type($frontend));

$broker = property($agent, 'permissions');
check('and asks through it', property($broker, 'approver') === $frontend, get_debug_type(property($broker, 'approver')));

$plan = $container->get(PlanReadyTool::class);
check('plan_ready asks through the same one', property($plan, 'approver') === $frontend);

$delegate = $container->get(DelegateTool::class);
check('a sub-agent shows its work through it too', property($delegate, 'output') === $frontend);

$shell = $container->get(ShellExecTool::class);
check('shell_exec hears the interrupt', property($shell, 'interrupt') instanceof Interrupt);

$files = property($container->get(FileReadTool::class), 'files');
check(
    'the file tools read and write through one shared place',
    $files instanceof Files
    && property($container->get(FileWriteTool::class), 'files') === $files
    && property($container->get(FilePatchTool::class), 'files') === $files,
);
$acp = new Application($kernel)->find('acp');
$acp = $acp instanceof LazyCommand ? $acp->getCommand() : $acp;
check('the one `sherpa acp` points at the editor', property(property($acp, 'server'), 'files') === $files);
check('and the agent loop it runs is the terminal\'s, with the same frontend switch', property(property($acp, 'server'), 'agent') === $agent);

// ---- switched over --------------------------------------------------------------
$recorder = new Recorder();
$frontend->use($recorder, $recorder);
$frontend->beginAssistantMessage();
$frontend->appendToken('Hi');
$frontend->flushBuffer();
$frontend->showToolCall('file_read', ['path' => 'a.php'], 'c1');
$frontend->showToolResult('file_read', 'x', false, 'c1');
$frontend->showInfo('note');
$choice = $frontend->confirm(new ToolCall('c2', 'shell_exec', []), new ToolDefinition('shell_exec', '', [], Permission::CONFIRM, $shell), new Offer());
$approved = $frontend->approvePlan('the plan');
check(
    'once switched, everything reaches the new frontend, call ids included',
    $recorder->seen === ['begin', 'token:Hi', 'flush', 'call:file_read:c1', 'result:file_read:c1', 'info:note', 'confirm:c2', 'plan:the plan'],
    json_encode($recorder->seen),
);
check('and its answers come back', $choice === ConfirmChoice::Session && $approved === false);
check('a sub-agent follows the switch', $frontend->forSubagent() === $recorder);

// ---- the edit, whole ------------------------------------------------------------
$project = sys_get_temp_dir() . '/sherpa-frontend-' . bin2hex(random_bytes(4));
mkdir($project, 0777, true);
file_put_contents("{$project}/Invoice.php", "<?php\nreturn \$price;\n");
$paths = new ProjectPathResolver();
$paths->setRoot($project);
$preview = new EditPreview(new FileWriteTool($paths), new FilePatchTool($paths));

$new = $preview->of(new ToolCall('c', 'file_write', ['path' => 'New.php', 'content' => "<?php\n"]));
check('a new file: no old text, the absolute path', $new instanceof ProposedEdit && $new->old === null && $new->new === "<?php\n" && $new->path === "{$project}/New.php", json_encode($new));

$over = $preview->of(new ToolCall('c', 'file_write', ['path' => 'Invoice.php', 'content' => "<?php\n"]));
check('an overwrite: the file as it is, and as it will be', $over?->old === "<?php\nreturn \$price;\n" && $over->new === "<?php\n");

$patch = $preview->of(new ToolCall('c', 'file_patch', ['path' => 'Invoice.php', 'search' => 'return $price;', 'replace' => 'return $price * $qty;']));
check('a patch: the whole file before and after', $patch?->new === "<?php\nreturn \$price * \$qty;\n" && $patch->old === "<?php\nreturn \$price;\n", json_encode($patch));

check(
    'a patch that will fail has no edit to show',
    !$preview->of(new ToolCall('c', 'file_patch', ['path' => 'Invoice.php', 'search' => 'nowhere', 'replace' => 'x'])) instanceof ProposedEdit,
);
check(
    'nor a path outside the project',
    !$preview->of(new ToolCall('c', 'file_write', ['path' => '../outside.php', 'content' => 'x'])) instanceof ProposedEdit,
);
check('nor a call that edits nothing', !$preview->of(new ToolCall('c', 'file_read', ['path' => 'Invoice.php'])) instanceof ProposedEdit);
check('file_write is untouched by the preview', file_get_contents("{$project}/Invoice.php") === "<?php\nreturn \$price;\n" && !is_file("{$project}/New.php"));

// ---- files: the disk, or the editor ------------------------------------------------
check('lines as file() gives them', Files::lines("a\nb\n") === ['a', 'b'] && Files::lines("a\r\nb") === ['a', 'b']
    && Files::lines('') === [] && Files::lines("\n") === [''] && Files::lines("a\rb\n") === ["a\rb"]);

$shared = new Files();
check('with no editor, the disk', $shared->read("{$project}/Invoice.php") === "<?php\nreturn \$price;\n");
check('a file that is not there reads as none', $shared->read("{$project}/Nope.php") === null);
$writes = [];
$shared->through(
    fn(string $path): ?string => str_ends_with($path, 'Invoice.php') ? "<?php\n// in the tab\n" : null,
    function (string $path, string $text) use (&$writes): void {
        $writes[$path] = $text;
    },
);
check('through an editor, its text', $shared->read("{$project}/Invoice.php") === "<?php\n// in the tab\n");
check('and the disk for what it cannot give', $shared->read("{$project}/New.php") === null);
$tool = new FilePatchTool($paths, files: $shared);
$tool('Invoice.php', '// in the tab', '// patched in the tab');
check('a patch applies to the tab and goes back to it', ($writes["{$project}/Invoice.php"] ?? '') === "<?php\n// patched in the tab\n");
check('leaving the disk alone', file_get_contents("{$project}/Invoice.php") === "<?php\nreturn \$price;\n");
$read = (new FileReadTool($paths, files: $shared))('Invoice.php');
check('file_read reads the tab, numbered', str_contains($read, '2 | // in the tab'), $read);

// ---- a command cancelled by a message, not a signal ------------------------------
$interrupt = new Interrupt();
$tool = new ShellExecTool($paths, interrupt: $interrupt);
check('a command still runs to its end', trim($tool('echo fini')) === 'fini');

$interrupt->beginTurn();
$interrupt->request();
$started = microtime(true);
$out = $tool('echo début; sleep 5; echo jamais');
$took = microtime(true) - $started;
check('cancelled, a running command stops at once', $took < 2.5, sprintf('%.1f s', $took));
check('and says it was interrupted', str_starts_with($out, '(command interrupted by the user)'), $out);
check('with nothing it would have printed after', !str_contains($out, 'jamais'), $out);

exec('rm -rf ' . escapeshellarg($project));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
