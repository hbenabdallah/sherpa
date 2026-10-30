<?php

// Plan mode: the model reads and proposes, nothing changes until the plan is
// approved — and once it is, the work goes on in the same turn.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\AgentLoop;
use App\Agent\ContextBudget;
use App\Agent\HistoryCompactor;
use App\Agent\MessageBag;
use App\Agent\PlanMode;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Permission\Approver;
use App\Permission\ConfirmChoice;
use App\Permission\PermissionBroker;
use App\Platform\ModelInfo;
use App\Platform\ModelResidency;
use App\Platform\PlatformInterface;
use App\Project\DockerConfig;
use App\Project\Project;
use App\Runtime\Interrupt;
use App\Tool\PlanReadyTool;
use App\TUI\ChatPane;
use App\TUI\MarkdownRenderer;
use App\TUI\StatusBar;
use App\TUI\Terminal;

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

#[AsTool(name: 'write_it', description: 'Writes', permission: Permission::CONFIRM)]
final class WriteIt
{
    public int $ran = 0;

    public function __invoke(#[Param('what')] string $what = ''): string
    {
        $this->ran++;

        return "written {$what}";
    }
}

#[AsTool(name: 'read_it', description: 'Reads', permission: Permission::AUTO)]
final class ReadIt
{
    public int $ran = 0;

    public function __invoke(#[Param('what')] string $what = ''): string
    {
        $this->ran++;

        return "read {$what}";
    }
}

/** Answers the plan question as told, and keeps the plans it was shown. */
final class PlanAnswer implements Approver
{
    /** @var list<string> */
    public array $plans = [];

    public function __construct(private readonly bool $yes) {}

    public function confirm(App\Agent\Tool\ToolCall $call, App\Agent\Tool\ToolDefinition $def, App\Permission\Offer $offer): ConfirmChoice
    {
        return ConfirmChoice::Once;
    }

    public function approvePlan(string $plan): ?bool
    {
        $this->plans[] = $plan;

        return $this->yes;
    }
}

/** Grants every confirmation, and counts how often it was asked. */
final class YesBroker extends PermissionBroker
{
    public int $asked = 0;

    public function __construct() {}

    public function check(App\Agent\Tool\ToolCall $call, ?App\Agent\Tool\ToolDefinition $def): bool
    {
        if ($def?->permission === Permission::CONFIRM) {
            $this->asked++;
        }

        return true;
    }
}

final class Scripted implements PlatformInterface
{
    public array $seen = [];

    public function __construct(private array $replies) {}

    public function stream(array $messages, array $tools = [], ?callable $onToken = null, ?callable $onWait = null): array
    {
        $this->seen[] = $messages;

        return array_shift($this->replies) ?? ['role' => 'assistant', 'content' => 'done'];
    }

    public function concurrency(): int
    {
        return 1;
    }

    public function lastUsage(): ?array
    {
        return null;
    }

    public function lastTimings(): ?array
    {
        return null;
    }

    public function residency(): ?ModelResidency
    {
        return null;
    }

    public function modelName(): string
    {
        return 's';
    }

    public function contextWindow(): int
    {
        return 32768;
    }

    public function catalogue(): array
    {
        return [];
    }

    public function describeModel(string $model): ?ModelInfo
    {
        return null;
    }

    public function useModel(string $model, int $contextWindow): void {}

    public function name(): string
    {
        return 's';
    }

    public function isAvailable(): bool
    {
        return true;
    }
}

$call = fn(string $name, array $args) => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c' . random_int(1, 99999), 'function' => ['name' => $name, 'arguments' => $args]]]];

function run(PlatformInterface $model, array $tools, PlanMode $plan, PermissionBroker $broker): array
{
    $budget = new ContextBudget(contextWindow: 32768);
    $loop = new AgentLoop(
        $model,
        new Toolbox($tools),
        $broker,
        new ChatPane(new Terminal(), new MarkdownRenderer()),
        $budget,
        new HistoryCompactor($model, $budget),
        new Interrupt(),
        planMode: $plan,
    );
    $bag = new MessageBag();
    $bag->system('S');
    $bag->user($plan->frame('fix the subtotal'));
    ob_start();
    $result = $loop->run($bag);
    $screen = Terminal::plain(ob_get_clean());

    return [$result, $bag, $screen];
}

// ---- what plan mode blocks ---------------------------------------------------------
$plan = new PlanMode();
$defs = new Toolbox([new WriteIt(), new ReadIt()]);
check('off, it blocks nothing', !$plan->blocks($defs->find('write_it')));
$plan->on();
check('on, what asks before it runs is blocked', $plan->blocks($defs->find('write_it')));
check('what only reads is not', !$plan->blocks($defs->find('read_it')));
check('the request reaches the model with what plan mode means', str_contains($plan->frame('do X'), 'change nothing yet') && str_ends_with($plan->frame('do X'), 'do X'));
$plan->off();
check('and as it was once plan mode is off', $plan->frame('do X') === 'do X');

// ---- the whole turn: blocked, planned, approved, done -------------------------------------
$plan = new PlanMode();
$plan->on();
$write = new WriteIt();
$read = new ReadIt();
$broker = new YesBroker();
$answer = new PlanAnswer(true);
$ready = new PlanReadyTool($plan, $answer);
$model = new Scripted([
    $call('read_it', ['what' => 'Invoice.php']),
    $call('write_it', ['what' => 'too soon']),
    $call('plan_ready', ['plan' => "1. Multiply by the quantity in `subtotal()`\n2. Run the tests"]),
    $call('write_it', ['what' => 'the fix']),
    ['role' => 'assistant', 'content' => 'Fixed.'],
]);
[$result, $bag, $screen] = run($model, [$write, $read, $ready], $plan, $broker);

$history = json_encode($bag->all());
check('reading goes ahead while planning', $read->ran === 1);
check('a write before approval is refused, without asking the user', str_contains($history, 'Plan mode: nothing is changed') && $broker->asked === 1 && $write->ran === 1, $history);
check('the plan is shown', str_contains($answer->plans[0] ?? '', 'Multiply by the quantity'), json_encode($answer->plans));
check('and approved once', count($answer->plans) === 1);
check('approval ends plan mode', !$plan->isActive());
check('and the work goes on in the same turn: the write runs, asked for as usual', $write->ran === 1 && $broker->asked === 1 && $result->content === 'Fixed.', $result->content);

// Not approved: the turn stops, and plan mode stays.
$plan = new PlanMode();
$plan->on();
$model = new Scripted([$call('plan_ready', ['plan' => 'Do it.']), ['role' => 'assistant', 'content' => 'What should change?']]);
[$result] = run($model, [new WriteIt(), new PlanReadyTool($plan, new PlanAnswer(false))], $plan, new YesBroker());
check('a plan not approved leaves plan mode on', $plan->isActive());
check('and the model is told to wait', str_contains(json_encode($model->seen[1]), 'did not approve the plan yet'), json_encode(end($model->seen[1])));

check('outside plan mode, plan_ready changes nothing', str_contains((new PlanReadyTool(new PlanMode(), new PlanAnswer(true)))('x'), 'Not in plan mode'));

// ---- the status bar says it -------------------------------------------------------
$project = new Project(
    slug: 'p',
    name: 'p',
    path: '/tmp',
    memoryDb: '/tmp/m.db',
    docker: new DockerConfig(enabled: false),
    stack: '',
    createdAt: new DateTimeImmutable(),
    lastUsedAt: new DateTimeImmutable(),
);
$bar = (new ReflectionClass(StatusBar::class))->newInstanceWithoutConstructor();
ob_start();
$bar->render($project, 'm', planning: true);
check('the status bar says plan mode is on', str_contains(Terminal::plain(ob_get_clean()), 'plan mode'));

// ---- arguments cut off ---------------------------------------------------------------
// A reply cut by the output limit leaves a tool call's arguments as half a JSON
// object. They became [], the tool ran with nothing, and PHP's "too few
// arguments" told the model nothing about why: it sent the same write again.
$write = new WriteIt();
$broker = new YesBroker();
$model = new Scripted([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'cut1', 'function' => ['name' => 'write_it', 'arguments' => '{"what": "a very long file that never clo']]]],
    ['role' => 'assistant', 'content' => 'Done in parts.'],
]);
[$result, $bag, $screen] = run($model, [$write], new PlanMode(), $broker);
$history = json_encode($bag->all());
check('a call whose arguments were cut off is not run', $write->ran === 0, (string) $write->ran);
check('nor put to the user for approval', $broker->asked === 0, (string) $broker->asked);
check('the model is told they were cut, and how to go on', str_contains($history, 'not valid JSON') && str_contains($history, 'smaller'), $history);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
