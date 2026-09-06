<?php
// A turn about to run out — of tokens or of steps — asks the model for its
// answer first. Before, it ended on the stop notice: in the benchmark, two
// sessions had named the right classes and never said so.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\AgentLoop;
use App\Agent\ContextBudget;
use App\Agent\HistoryCompactor;
use App\Agent\MessageBag;
use App\Agent\Result;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Permission\PermissionBroker;
use App\Platform\ModelInfo;
use App\Platform\ModelResidency;
use App\Platform\PlatformInterface;
use App\Runtime\Interrupt;
use App\TUI\ChatPane;
use App\TUI\MarkdownRenderer;
use App\TUI\Terminal;
use App\Usage\TokenCapReached;
use App\Usage\UsageMeter;

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

#[AsTool(name: 'read_it', description: 'Reads', permission: Permission::AUTO)]
final class ReadIt
{
    public int $ran = 0;
    public function __invoke(#[Param('what')] string $what = ''): string { $this->ran++; return "read {$what}"; }
}

final class AllowAll extends PermissionBroker
{
    public function __construct() {}
    public function check(App\Agent\Tool\ToolCall $call, ?App\Agent\Tool\ToolDefinition $def): bool { return true; }
}

/**
 * A model that reads one more file every turn, and spends a fixed number of
 * tokens per request on a meter that refuses requests past the cap — as the
 * platform switch does in the real thing.
 */
final class Reader implements PlatformInterface
{
    public int $requests = 0;
    /** @var array<int, array<int, array<string, mixed>>> */
    public array $seen = [];

    /** @param \Closure(array<int, array<string, mixed>>, int): array<string, mixed> $reply */
    public function __construct(private readonly \Closure $reply, private readonly ?UsageMeter $meter = null, private readonly int $perRequest = 0) {}

    public function stream(array $messages, array $tools = [], ?callable $onToken = null, ?callable $onWait = null): array
    {
        $this->meter?->assertRoom();
        $this->requests++;
        $this->seen[] = $messages;
        $this->meter?->record(['prompt' => $this->perRequest, 'completion' => 0]);

        return ($this->reply)($messages, $this->requests);
    }
    public function lastUsage(): ?array { return null; }
    public function lastTimings(): ?array { return null; }
    public function residency(): ?ModelResidency { return null; }
    public function modelName(): string { return 'r'; }
    public function contextWindow(): int { return 1_000_000; }
    public function catalogue(): array { return []; }
    public function describeModel(string $model): ?ModelInfo { return null; }
    public function useModel(string $model, int $contextWindow): void {}
    public function name(): string { return 'r'; }
    public function isAvailable(): bool { return true; }
}

/**
 * Reads file n on request n. Told to stop, it answers — or, if it does not
 * obey, goes on reading, with $aside written alongside the call.
 */
function reader(bool $obeys, string $aside = ''): \Closure
{
    return function (array $messages, int $n) use ($obeys, $aside): array {
        $told = str_contains(json_encode($messages), 'Stop investigating');
        if ($told && $obeys) {
            return ['role' => 'assistant', 'content' => 'Found it: Invoice::subtotal().'];
        }

        return ['role' => 'assistant', 'content' => $told ? $aside : '', 'tool_calls' => [
            ['id' => "c{$n}", 'function' => ['name' => 'read_it', 'arguments' => ['what' => "file{$n}"]]],
        ]];
    };
}

/** @return array{0: Result|TokenCapReached, 1: string, 2: MessageBag} */
function turn(Reader $model, ReadIt $tool, ?UsageMeter $meter): array
{
    $budget = new ContextBudget(contextWindow: 1_000_000);
    $loop = new AgentLoop($model, new Toolbox([$tool]), new AllowAll(), new ChatPane(new Terminal(), new MarkdownRenderer()),
        $budget, new HistoryCompactor($model, $budget), new Interrupt(), usage: $meter);
    $bag = new MessageBag();
    $bag->system('S');
    $bag->user('why is the subtotal wrong?');

    ob_start();
    try {
        $result = $loop->run($bag);
    } catch (TokenCapReached $e) {
        $result = $e;
    }

    return [$result, Terminal::plain(ob_get_clean()), $bag];
}

// ---- the meter says when there is no room for another round ------------------
$meter = new UsageMeter();
$meter->record(['prompt' => 9000, 'completion' => 0]);
check('without a cap, never nearly spent', !$meter->nearlySpent());

$meter = new UsageMeter(cap: 10_000);
check('nor before the first request', !$meter->nearlySpent());
$meter->record(['prompt' => 3000, 'completion' => 0]);
check('room for two more requests the size of the last: not yet', !$meter->nearlySpent());
$meter->record(['prompt' => 3000, 'completion' => 500]);
check('less than that: nearly spent', $meter->nearlySpent());
$meter->record(['prompt' => 4000, 'completion' => 0]);
check('and once spent, the cap refuses: nothing left to answer with', !$meter->nearlySpent());

// ---- the token cap: an answer instead of the refusal ---------------------------
$meter = new UsageMeter(cap: 10_000);
$tool = new ReadIt();
$model = new Reader(reader(obeys: true), $meter, 3000);
[$result, $screen] = turn($model, $tool, $meter);

check('a turn about to hit the cap ends on an answer', $result instanceof Result && $result->content === 'Found it: Invoice::subtotal().',
    $result instanceof Result ? $result->content : $result->getMessage());
check('asked for while there was still room for it', $model->requests === 3 && $meter->total() <= 10_000, "{$model->requests} requests, {$meter->total()} tokens");
check('the tools it had called before ran', $tool->ran === 2, (string) $tool->ran);
check('the user is told why the model stopped looking', str_contains($screen, 'the token budget of this session is nearly spent'), $screen);
$last = end($model->seen);
check('the model is told to answer without tools, and to say what it could not check',
    str_contains(json_encode($last), 'without calling any tool') && str_contains(json_encode($last), 'could not check'));

// Without the meter, the same turn runs into the cap as before.
$meter = new UsageMeter(cap: 10_000);
$model = new Reader(reader(obeys: true), $meter, 3000);
[$result] = turn($model, new ReadIt(), null);
check('without it, the cap stops the turn with nothing said', $result instanceof TokenCapReached);

// ---- a model that will not stop ------------------------------------------------
$meter = new UsageMeter(cap: 10_000);
$tool = new ReadIt();
$model = new Reader(reader(obeys: false, aside: 'So far: Invoice::subtotal().'), $meter, 2000);
[$result, , $bag] = turn($model, $tool, $meter);

check('a call made after being told to answer is not run', $tool->ran === 4 && $model->requests === 5, "{$tool->ran} run, {$model->requests} requests");
check('what the model wrote alongside it is the answer',
    $result instanceof Result && $result->content === 'So far: Invoice::subtotal().',
    $result instanceof Result ? $result->content : $result->getMessage());
$history = json_encode($bag->all());
check('the refused call is answered, so the history stays well formed', substr_count($history, 'Not run: there is no room left') === 1, $history);

$meter = new UsageMeter(cap: 100_000);
$tool = new ReadIt();
$model = new Reader(reader(obeys: false), $meter, 20_000);
[$result] = turn($model, $tool, $meter);
check('written nothing, the turn says why it stopped instead of running into the cap',
    $result instanceof Result && str_contains($result->content, 'kept calling tools') && $model->requests === 5 && $tool->ran === 4,
    ($result instanceof Result ? $result->content : $result->getMessage()) . " · {$model->requests} requests, {$tool->ran} run");

// ---- the step limit too --------------------------------------------------------
$tool = new ReadIt();
$model = new Reader(reader(obeys: true));
[$result, $screen] = turn($model, $tool, null);
check('a turn at its last steps ends on an answer, not on "maximum iterations"',
    $result instanceof Result && $result->content === 'Found it: Invoice::subtotal().' && !$result->maxIterationsReached,
    $result instanceof Result ? $result->content : '');
check('and says why', str_contains($screen, 'this turn has reached its limit of steps'));

// Out of steps, not of tokens: a model that writes nothing is asked once more.
$tool = new ReadIt();
$model = new Reader(reader(obeys: false));
[$result] = turn($model, $tool, null);
check('out of steps, a silent model is asked once more before the turn ends',
    $result instanceof Result && str_contains($result->content, 'kept calling tools') && $model->requests === 40 && $tool->ran === 38,
    ($result instanceof Result ? $result->content : '') . " · {$model->requests} requests, {$tool->ran} run");

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
