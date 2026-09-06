<?php
// Per-project permissions: they have to survive the process that granted them,
// and they have to be revocable.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\Tool\Permission;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;
use App\Permission\ConfirmChoice;
use App\Permission\PermissionBroker;
use App\Permission\ProjectPermissions;
use App\Permission\SessionPermissions;
use App\Project\DockerConfig;
use App\Project\Project;
use App\Project\ProjectStore;
use App\Project\StackDetector;
use App\Tool\FilePatchTool;
use App\Tool\FileWriteTool;
use App\Tool\ShellExecTool;
use App\TUI\ConfirmOverlay;
use App\TUI\Terminal;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo "        ", substr($detail, 0, 240), "\n";
    }
}

// A throwaway HOME: Project::configDir() reads $_SERVER['HOME'], and these tests
// write projects.yaml. Without this they would overwrite the real one.
$realHome = $_SERVER['HOME'] ?? null;
$home = sys_get_temp_dir() . '/sherpa-perm-' . bin2hex(random_bytes(4));
mkdir($home . '/.config/sherpa/projects', 0777, true);
$_SERVER['HOME'] = $home;

$projectDir = $home . '/demo';
mkdir($projectDir, 0777, true);

// ---- persistence round-trip ------------------------------------------------
$store = new ProjectStore(new StackDetector());
$project = $store->create('Demo', $projectDir, new DockerConfig(enabled: false));

check('a new project starts with no standing grants', $project->allowedTools === []);

$store->allowTool($project, 'shell_exec');
check('the grant is held in memory', in_array('shell_exec', $project->allowedTools, true));

$yaml = file_get_contents($home . '/.config/sherpa/projects.yaml');
check('the grant reaches projects.yaml', str_contains($yaml, 'shell_exec'), $yaml);

// The whole point: a different process must see it.
$reloaded = (new ProjectStore(new StackDetector()))->get($project->slug);
check('a fresh store reads the grant back', $reloaded !== null && in_array('shell_exec', $reloaded->allowedTools, true));

$store->allowTool($project, 'shell_exec');
check('granting twice does not duplicate', count(array_keys($project->allowedTools, 'shell_exec', true)) === 1);

check('revoking a granted tool reports success', $store->revokeTool($project, 'shell_exec'));
check('and it is gone from memory', !in_array('shell_exec', $project->allowedTools, true));
check(
    'and gone from disk',
    !in_array('shell_exec', (new ProjectStore(new StackDetector()))->get($project->slug)->allowedTools, true),
);
check('revoking what was never granted reports failure', !$store->revokeTool($project, 'file_write'));

$store->allowTool($project, 'shell_exec');
$store->allowTool($project, 'file_write');
check('clearing reports how many went', $store->revokeAllTools($project) === 2);
check('clearing an empty list is a no-op', $store->revokeAllTools($project) === 0);

// Grants must not leak between projects.
$otherDir = $home . '/other';
mkdir($otherDir, 0777, true);
$other = $store->create('Other', $otherDir, new DockerConfig(enabled: false));
$store->allowTool($project, 'shell_exec');
check('a grant does not leak to another project', $other->allowedTools === []);

// ---- ProjectPermissions ----------------------------------------------------
$perms = new ProjectPermissions($store);
check('nothing is allowed before a project is bound', !$perms->isAllowed('shell_exec'));

$perms->allow('shell_exec');
check('granting with no project bound grants nothing', !$perms->isAllowed('shell_exec'));

$perms->setProject($project);
check('a grant made earlier is visible once bound', $perms->isAllowed('shell_exec'));
check('an ungranted tool is still refused', !$perms->isAllowed('file_patch'));

$perms->allow('file_patch');
check('allow() persists through the store', in_array('file_patch', (new ProjectStore(new StackDetector()))->get($project->slug)->allowedTools, true));
check('revoke() reports success', $perms->revoke('file_patch'));
check('revoke() of an unknown tool reports failure', !$perms->revoke('file_patch'));

// ---- the broker ------------------------------------------------------------
/** An overlay that answers without a terminal, and counts how often it is asked. */
final class ScriptedOverlay extends ConfirmOverlay
{
    public int $asked = 0;

    public function __construct(private readonly ConfirmChoice $answer)
    {
        parent::__construct(new Terminal(), new FileWriteTool(), new FilePatchTool(), new ShellExecTool());
    }

    public function show(ToolCall $call, ToolDefinition $def): ConfirmChoice
    {
        $this->asked++;

        return $this->answer;
    }
}

function definition(string $name, Permission $permission): ToolDefinition
{
    return new ToolDefinition($name, 'desc', [], $permission, new ShellExecTool());
}

function call(string $name): ToolCall
{
    return new ToolCall('id', $name, []);
}

function broker(ConfirmChoice $answer, ProjectPermissions $perms): array
{
    $overlay = new ScriptedOverlay($answer);

    return [new PermissionBroker(new SessionPermissions(), $overlay, $perms), $overlay];
}

$store->revokeAllTools($project);

[$b, $overlay] = broker(ConfirmChoice::Deny, $perms);
check('AUTO runs without asking', $b->check(call('file_read'), definition('file_read', Permission::AUTO)));
check('DENY is refused without asking', !$b->check(call('x'), definition('x', Permission::DENY)));
check('neither reached the overlay', $overlay->asked === 0);

check('a refused confirmation blocks the call', !$b->check(call('shell_exec'), definition('shell_exec', Permission::CONFIRM)));
check('and it did reach the overlay', $overlay->asked === 1);

[$b, $overlay] = broker(ConfirmChoice::Once, $perms);
$b->check(call('shell_exec'), definition('shell_exec', Permission::CONFIRM));
$b->check(call('shell_exec'), definition('shell_exec', Permission::CONFIRM));
check('"once" asks again the next time', $overlay->asked === 2);
check('"once" persists nothing', $perms->all() === [], json_encode($perms->all()));

[$b, $overlay] = broker(ConfirmChoice::Session, $perms);
$b->check(call('shell_exec'), definition('shell_exec', Permission::CONFIRM));
$b->check(call('shell_exec'), definition('shell_exec', Permission::CONFIRM));
check('"session" asks only once', $overlay->asked === 1);
check('"session" persists nothing', $perms->all() === [], json_encode($perms->all()));

[$b, $overlay] = broker(ConfirmChoice::Project, $perms);
check('"always" allows the call', $b->check(call('shell_exec'), definition('shell_exec', Permission::CONFIRM)));
$b->check(call('shell_exec'), definition('shell_exec', Permission::CONFIRM));
check('"always" asks only once', $overlay->asked === 1);
check('"always" persists to disk', in_array('shell_exec', (new ProjectStore(new StackDetector()))->get($project->slug)->allowedTools, true));

// The regression that started all this: a brand new session must honour it.
$freshPerms = new ProjectPermissions($freshStore = new ProjectStore(new StackDetector()));
$freshPerms->setProject($freshStore->get($project->slug));
[$b, $overlay] = broker(ConfirmChoice::Deny, $freshPerms);
check('a later session honours the standing grant', $b->check(call('shell_exec'), definition('shell_exec', Permission::CONFIRM)));
check('and never opens the overlay', $overlay->asked === 0);

// Revoking brings the prompt back.
$freshPerms->revoke('shell_exec');
check('revoking restores the confirmation', !$b->check(call('shell_exec'), definition('shell_exec', Permission::CONFIRM)));
check('and the overlay is asked again', $overlay->asked === 1);

// A standing grant must not spill onto other tools.
$freshPerms->allow('shell_exec');
[$b, $overlay] = broker(ConfirmChoice::Deny, $freshPerms);
check('a grant covers only the tool named', !$b->check(call('file_write'), definition('file_write', Permission::CONFIRM)));
check('so the overlay is still consulted for others', $overlay->asked === 1);

// ---- cleanup ---------------------------------------------------------------
if ($realHome !== null) {
    $_SERVER['HOME'] = $realHome;
}
exec('rm -rf ' . escapeshellarg($home));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
