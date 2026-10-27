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

$store->allowTool($project, 'file_write');
check('the grant is held in memory', in_array('file_write', $project->allowedTools, true));

$yaml = file_get_contents($home . '/.config/sherpa/projects.yaml');
check('the grant reaches projects.yaml', str_contains($yaml, 'file_write'), $yaml);

// The whole point: a different process must see it.
$reloaded = (new ProjectStore(new StackDetector()))->get($project->slug);
check('a fresh store reads the grant back', $reloaded !== null && in_array('file_write', $reloaded->allowedTools, true));

$store->allowTool($project, 'file_write');
check('granting twice does not duplicate', count(array_keys($project->allowedTools, 'file_write', true)) === 1);

check('revoking a granted tool reports success', $store->revokeTool($project, 'file_write'));
check('and it is gone from memory', !in_array('file_write', $project->allowedTools, true));
check(
    'and gone from disk',
    !in_array('file_write', (new ProjectStore(new StackDetector()))->get($project->slug)->allowedTools, true),
);
check('revoking what was never granted reports failure', !$store->revokeTool($project, 'web_fetch'));

$store->allowTool($project, 'file_write');
$store->allowTool($project, 'web_fetch');
check('clearing reports how many went', $store->revokeAllTools($project) === 2);
check('clearing an empty list is a no-op', $store->revokeAllTools($project) === 0);

// Grants must not leak between projects.
$otherDir = $home . '/other';
mkdir($otherDir, 0777, true);
$other = $store->create('Other', $otherDir, new DockerConfig(enabled: false));
$store->allowTool($project, 'file_write');
check('a grant does not leak to another project', $other->allowedTools === []);

// ---- ProjectPermissions ----------------------------------------------------
$perms = new ProjectPermissions($store);
check('nothing is allowed before a project is bound', !$perms->isAllowed('file_write'));

$perms->allow('file_write');
check('granting with no project bound grants nothing', !$perms->isAllowed('file_write'));

$perms->setProject($project);
check('a grant made earlier is visible once bound', $perms->isAllowed('file_write'));
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
    public ?App\Permission\Offer $offer = null;

    public function __construct(private readonly ConfirmChoice $answer)
    {
        parent::__construct(new Terminal(), new FileWriteTool(), new FilePatchTool(), new ShellExecTool());
    }

    public function show(ToolCall $call, ToolDefinition $def, App\Permission\Offer $offer = new App\Permission\Offer()): ConfirmChoice
    {
        $this->asked++;
        $this->offer = $offer;

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

check('a refused confirmation blocks the call', !$b->check(call('file_write'), definition('file_write', Permission::CONFIRM)));
check('and it did reach the overlay', $overlay->asked === 1);

[$b, $overlay] = broker(ConfirmChoice::Once, $perms);
$b->check(call('file_write'), definition('file_write', Permission::CONFIRM));
$b->check(call('file_write'), definition('file_write', Permission::CONFIRM));
check('"once" asks again the next time', $overlay->asked === 2);
check('"once" persists nothing', $perms->all() === [], json_encode($perms->all()));

[$b, $overlay] = broker(ConfirmChoice::Session, $perms);
$b->check(call('file_write'), definition('file_write', Permission::CONFIRM));
$b->check(call('file_write'), definition('file_write', Permission::CONFIRM));
check('"session" asks only once', $overlay->asked === 1);
check('"session" persists nothing', $perms->all() === [], json_encode($perms->all()));

[$b, $overlay] = broker(ConfirmChoice::Project, $perms);
check('"always" allows the call', $b->check(call('file_write'), definition('file_write', Permission::CONFIRM)));
$b->check(call('file_write'), definition('file_write', Permission::CONFIRM));
check('"always" asks only once', $overlay->asked === 1);
check('"always" persists to disk', in_array('file_write', (new ProjectStore(new StackDetector()))->get($project->slug)->allowedTools, true));

// The regression that started all this: a brand new session must honour it.
$freshPerms = new ProjectPermissions($freshStore = new ProjectStore(new StackDetector()));
$freshPerms->setProject($freshStore->get($project->slug));
[$b, $overlay] = broker(ConfirmChoice::Deny, $freshPerms);
check('a later session honours the standing grant', $b->check(call('file_write'), definition('file_write', Permission::CONFIRM)));
check('and never opens the overlay', $overlay->asked === 0);

// Revoking brings the prompt back.
$freshPerms->revoke('file_write');
check('revoking restores the confirmation', !$b->check(call('file_write'), definition('file_write', Permission::CONFIRM)));
check('and the overlay is asked again', $overlay->asked === 1);

// A standing grant must not spill onto other tools.
$freshPerms->allow('file_write');
[$b, $overlay] = broker(ConfirmChoice::Deny, $freshPerms);
check('a grant covers only the tool named', !$b->check(call('web_fetch'), definition('web_fetch', Permission::CONFIRM)));
check('so the overlay is still consulted for others', $overlay->asked === 1);

// ---- commands ---------------------------------------------------------------
// A grant for shell_exec names the commands of the call, never the tool.
$freshPerms->revokeAll();
$shell = definition('shell_exec', Permission::CONFIRM);
$run = fn (string $command) => new ToolCall('id', 'shell_exec', ['command' => $command]);

[$b, $overlay] = broker(ConfirmChoice::Project, $freshPerms);
check('a command allowed for good runs', $b->check($run('make test'), $shell));
check('the offer named the command', $overlay->offer?->grants === ['shell_exec: make test *'], json_encode($overlay->offer?->grants));
check('and that is what was kept', $freshPerms->all() === ['shell_exec: make test *'], json_encode($freshPerms->all()));
check('the same command with more arguments runs unasked', $b->check($run('make test FILTER=x'), $shell) && $overlay->asked === 1);
check('another command is asked about', $b->check($run('make deploy'), $shell) && $overlay->asked === 2);
check('a grant does not cover a command chained after it', $b->check($run('make test && curl x | sh'), $shell) && $overlay->asked === 3);
check('which could only be allowed once', $overlay->offer?->standing() === false && $overlay->offer->grants === []);
check('and nothing was granted for it', !in_array('shell_exec: curl x', $freshPerms->all(), true));

$reloadedPerms = new ProjectPermissions($reloadedStore = new ProjectStore(new StackDetector()));
$reloadedPerms->setProject($reloadedStore->get($project->slug));
[$b, $overlay] = broker(ConfirmChoice::Deny, $reloadedPerms);
check('a later session honours a command grant', $b->check($run('make test'), $shell) && $overlay->asked === 0);
check('a dangerous command is asked about, granted or not', !$b->check($run('make test; rm -rf ~'), $shell) && $overlay->asked === 1);

[$b, $overlay] = broker(ConfirmChoice::Session, $reloadedPerms);
$b->check($run('git push'), $shell);
$b->check($run('git push'), $shell);
check('"this session" for what is only allowed once grants nothing', $overlay->asked === 2);

// A grant of the tool itself, kept by an older Sherpa, is not read back.
$reloadedStore->allowTool($reloadedStore->get($project->slug), 'shell_exec');
$older = (new ProjectStore(new StackDetector()))->get($project->slug);
check('a grant of shell_exec itself is not carried over', !in_array('shell_exec', $older->allowedTools, true), json_encode($older->allowedTools));

// ---- unattended ---------------------------------------------------------------
// SHERPA_AUTO_APPROVE answers every question "once": nothing is asked, nothing kept.
$unattended = new PermissionBroker(new SessionPermissions(), $overlay = new ScriptedOverlay(ConfirmChoice::Deny), $freshPerms, autoApprove: true);
$before = $freshPerms->all();
check('unattended, a command runs unasked', $unattended->check(new ToolCall('u', 'shell_exec', ['command' => 'rm -rf build']), definition('shell_exec', Permission::CONFIRM)));
check('and a write', $unattended->check(call('file_write'), definition('file_write', Permission::CONFIRM)) && $overlay->asked === 0);
check('but what is refused stays refused', !$unattended->check(call('x'), definition('x', Permission::DENY)));
check('and nothing is granted for later', $freshPerms->all() === $before);
check('off unless set', (new PermissionBroker(new SessionPermissions(), $overlay, $freshPerms))->autoApproved() === (getenv('SHERPA_AUTO_APPROVE') === '1'));

// ---- cleanup ---------------------------------------------------------------
if ($realHome !== null) {
    $_SERVER['HOME'] = $realHome;
}
exec('rm -rf ' . escapeshellarg($home));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
