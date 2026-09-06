<?php
// The model backend behind an interface — and the proof that the interface is
// real, by running the whole agent loop through a second implementation that
// has never heard of Ollama or of HTTP.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\AgentLoop;
use App\Agent\ContextBudget;
use App\Agent\HistoryCompactor;
use App\Agent\InferenceSpeed;
use App\Agent\MessageBag;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolResult;
use App\Kernel;
use App\Permission\PermissionBroker;
use App\Permission\ProjectPermissions;
use App\Permission\SessionPermissions;
use App\Config\Backend;
use App\Platform\OllamaPlatform;
use App\Platform\OpenAiCompatiblePlatform;
use App\Platform\SwitchablePlatform;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use App\Platform\ModelInfo;
use App\Platform\ModelResidency;
use App\Platform\PlatformInterface;
use App\Project\ProjectStore;
use App\Project\StackDetector;
use App\Runtime\Interrupt;
use App\Tool\FilePatchTool;
use App\Tool\FileWriteTool;
use App\Tool\ShellExecTool;
use App\TUI\ChatPane;
use App\TUI\ConfirmOverlay;
use App\TUI\MarkdownRenderer;
use App\TUI\Terminal;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo "        ", substr($detail, 0, 300), "\n";
    }
}

$realHome = $_SERVER['HOME'] ?? null;
$home = sys_get_temp_dir() . '/sherpa-plat-' . bin2hex(random_bytes(4));
mkdir($home . '/.config/sherpa', 0777, true);
$_SERVER['HOME'] = $home;

/**
 * A backend with no HTTP client, no NDJSON and no Ollama: it reads replies off
 * a script. If the loop can be driven by this, nothing Ollama-specific is left
 * in it.
 */
final class ScriptedPlatform implements PlatformInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $sawMessages = [];
    public array $sawTools = [];
    public int $calls = 0;

    private ?array $usage = null;

    /** @param array<int, array<string, mixed>> $replies */
    public function __construct(
        private array $replies,
        private readonly bool $reportsUsage = true,
        // Tokens per second this fake claims to run at, or null for a backend
        // that reports no durations at all.
        private readonly ?array $timings = null,
    ) {}

    public function stream(array $messages, array $tools = [], ?callable $onToken = null, ?callable $onWait = null): array
    {
        $this->calls++;
        $this->sawMessages = $messages;
        $this->sawTools = $tools;

        // Derived from what was actually sent, so the count is plausible.
        // ContextBudget rejects a ratio that cannot correspond to the payload,
        // which is right: a truncated request reports nonsense.
        $this->usage = $this->reportsUsage
            ? ['prompt' => max(1, (int) (mb_strlen(json_encode($messages) . json_encode($tools)) / 3.5)), 'completion' => 10]
            : null;

        $reply = array_shift($this->replies) ?? ['role' => 'assistant', 'content' => 'fini'];

        if ($onToken !== null && ($reply['content'] ?? '') !== '') {
            foreach (str_split($reply['content'], 4) as $piece) {
                $onToken($piece);
            }
        }

        return $reply;
    }

    public function lastUsage(): ?array { return $this->usage; }
    public function lastTimings(): ?array { return $this->timings; }
    public function residency(): ?ModelResidency { return null; }
    public function modelName(): string { return $this->model; }
    public function name(): string { return 'Script'; }
    public function isAvailable(): bool { return true; }

    // The selection half of the contract. A backend that cannot enumerate its
    // models returns an empty catalogue — which is a supported state, not a
    // failure, because a name can always be typed instead.
    public string $model = 'scripted';
    public int $window = 32768;

    public function contextWindow(): int { return $this->window; }
    public function catalogue(): array { return []; }
    public function describeModel(string $model): ?ModelInfo { return null; }

    public function useModel(string $model, int $contextWindow): void
    {
        $this->model = $model;
        $this->window = $contextWindow;
    }
}

#[AsTool(name: 'count', description: 'Compte quelque chose', permission: Permission::AUTO)]
final class CountTool
{
    public int $ran = 0;

    public function __invoke(#[Param('what')] string $what = ''): string
    {
        $this->ran++;

        return "counted: {$what}";
    }
}

/**
 * A toolbox whose every call fails outside the tool itself — where Toolbox's
 * own catch cannot turn it into an error result. Stands in for a confirmation
 * prompt, the pane or a counter throwing mid-turn.
 */
final class ExplodingToolbox extends Toolbox
{
    public function execute(ToolCall $call): ToolResult
    {
        throw new RuntimeException('le terminal a disparu');
    }
}

/**
 * Everything wrong with a history's tool calls, as a hosted API would see it:
 * calls without an id, results naming no pending call, calls never answered.
 *
 * @return string[]
 */
function unpairedCalls(array $messages): array
{
    $open = [];
    $problems = [];

    foreach ($messages as $message) {
        foreach ($message['tool_calls'] ?? [] as $call) {
            if (!is_string($call['id'] ?? null)) {
                $problems[] = 'call without an id';
                continue;
            }
            $open[$call['id']] = true;
        }

        if (($message['role'] ?? '') === 'tool') {
            $id = $message['tool_call_id'] ?? null;
            if (!is_string($id) || !isset($open[$id])) {
                $problems[] = 'result for no pending call: ' . json_encode($id);
                continue;
            }
            unset($open[$id]);
        }
    }

    foreach (array_keys($open) as $id) {
        $problems[] = "call {$id} never answered";
    }

    return $problems;
}

/** @return array{0: \App\Agent\Result|\Throwable, 1: MessageBag, 2: ContextBudget, 3: InferenceSpeed} */
function loopWith(PlatformInterface $platform, array $tools = [], string $toolbox = Toolbox::class): array
{
    $budget = new ContextBudget(contextWindow: 32768);
    $speed = new InferenceSpeed();
    $loop = new AgentLoop(
        $platform,
        new $toolbox($tools),
        new PermissionBroker(
            new SessionPermissions(),
            new ConfirmOverlay(new Terminal(), new FileWriteTool(), new FilePatchTool(), new ShellExecTool()),
            new ProjectPermissions(new ProjectStore(new StackDetector())),
        ),
        new ChatPane(new Terminal(), new MarkdownRenderer()),
        $budget,
        new HistoryCompactor($platform, $budget, $speed),
        new Interrupt(),
        new App\Agent\Tool\TextToolCallParser(),
        $speed,
    );

    $bag = new MessageBag();
    $bag->system('SYSTEM');
    $bag->user('vas-y');

    ob_start();
    try {
        $result = $loop->run($bag);
    } catch (Throwable $e) {
        $result = $e;
    } finally {
        ob_end_clean();
    }

    return [$result, $bag, $budget, $speed];
}

// ---- the contract ----------------------------------------------------------
check('the Ollama backend satisfies the interface', is_subclass_of(OllamaPlatform::class, PlatformInterface::class));

$reflection = new ReflectionClass(PlatformInterface::class);
$methods = array_map(fn($m) => $m->getName(), $reflection->getMethods());
sort($methods);
// Still a guard, not a rubber stamp: every name below has a caller in src/.
// catalogue/describeModel/useModel/contextWindow arrived with model selection —
// the note at the foot of PlatformInterface said they would, once something
// asked. Anything added here without a caller is what this check is for.
check('the contract is only what callers actually need',
    $methods === ['catalogue', 'contextWindow', 'describeModel', 'isAvailable', 'lastTimings', 'lastUsage', 'modelName', 'name', 'residency', 'stream', 'useModel'],
    implode(',', $methods));

// chat() left under the rule that put the others here. Its one caller was the
// compactor's summariser, which moved to stream() so Ctrl+C could reach it —
// and a blocking twin with no callers is just the uncancellable way to do the
// same thing.
check('there is no second, uncancellable way to call the model', !in_array('chat', $methods, true));

// ---- a whole turn, with no Ollama anywhere ---------------------------------
$scripted = new ScriptedPlatform([['role' => 'assistant', 'content' => 'here is the reply']]);
[$result, $bag] = loopWith($scripted);

check('the loop runs a turn through a foreign backend', $result->content === 'here is the reply', $result->content);
check('and the reply is written to history', in_array('assistant', array_column($bag->all(), 'role'), true));
check('the backend was handed the conversation', ($scripted->sawMessages[0]['content'] ?? null) === 'SYSTEM', json_encode($scripted->sawMessages));

// Tool calling too: this is where the wire format would leak if it had.
$tool = new CountTool();
$scripted = new ScriptedPlatform([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'count', 'arguments' => ['what' => 'the sheep']]]]],
    ['role' => 'assistant', 'content' => 'three sheep'],
]);
[$result, $bag] = loopWith($scripted, [$tool]);

check('a tool call round-trips through the interface', $tool->ran === 1 && $result->content === 'three sheep', $result->content);
check('the tool result went back to the backend',
    str_contains(json_encode($scripted->sawMessages, JSON_UNESCAPED_UNICODE), 'counted: the sheep'),
    json_encode($scripted->sawMessages, JSON_UNESCAPED_UNICODE));
check('and the tool schemas were passed along', ($scripted->sawTools[0]['function']['name'] ?? null) === 'count', json_encode($scripted->sawTools));

// ---- a backend that counts nothing -----------------------------------------
// The interface says lastUsage() may be null. The budget must then stay on its
// estimate rather than calibrate against a number nobody reported.
$silent = new ScriptedPlatform([['role' => 'assistant', 'content' => 'ok']], reportsUsage: false);
[, , $budget] = loopWith($silent);
check('a backend that reports no token counts does not break the budget', !$budget->isCalibrated());
check('while one that reports them calibrates it', (function () {
    $counting = new ScriptedPlatform([['role' => 'assistant', 'content' => 'ok']]);
    [, , $budget] = loopWith($counting);

    return $budget->isCalibrated();
})());

// ---- the machine measures itself -------------------------------------------
// Everything that used to be a constant guessing at the machine now asks
// InferenceSpeed, and InferenceSpeed only knows what the loop fed it.
$timed = new ScriptedPlatform(
    [['role' => 'assistant', 'content' => 'ok']],
    timings: ['prefill' => 800.0, 'decode' => 40.0],
);
[, , , $speed] = loopWith($timed);

check('the loop feeds throughput into InferenceSpeed', $speed->isMeasured());
check('prefill is carried through unaltered on the first sample',
    $speed->prefillTokensPerSecond() === 800.0, (string) $speed->prefillTokensPerSecond());
check('and a re-read of the window is costed from it',
    (int) round($speed->secondsToPrefill(8000) ?? 0) === 10, (string) $speed->secondsToPrefill(8000));

$untimed = new ScriptedPlatform([['role' => 'assistant', 'content' => 'ok']]);
[, , , $speed] = loopWith($untimed);
check('a backend reporting no durations leaves the machine unmeasured', !$speed->isMeasured());

// ---- a model going in circles ----------------------------------------------
// Ten iterations used to be the only stop, which capped honest work on a fast
// machine and still let a stuck model burn all ten. Repetition is the actual
// fault, and it is caught in three.
$circling = new ScriptedPlatform(array_fill(0, 12, [
    'role' => 'assistant',
    'content' => '',
    'tool_calls' => [['function' => ['name' => 'count', 'arguments' => ['what' => 'again']]]],
]));
$tool = new CountTool();
[$result, $bag] = loopWith($circling, [$tool]);

check('a repeated tool call stops the turn', $result->repetitionDetected, $result->content);
check('it stops within three attempts, not ten', $tool->ran <= 3, (string) $tool->ran);
check('and it is not reported as the iteration cap', !$result->maxIterationsReached);

// The history must stay well-formed: the assistant message carrying those
// calls is in it, so every call needs a matching tool message or the next
// request the user makes is malformed.
$assistantCalls = 0;
$toolReplies = 0;
foreach ($bag->all() as $message) {
    $assistantCalls += count($message['tool_calls'] ?? []);
    $toolReplies += ($message['role'] ?? '') === 'tool' ? 1 : 0;
}
check('every tool call still has a matching result', $assistantCalls === $toolReplies,
    "{$assistantCalls} calls / {$toolReplies} results");
check('and each stub names the call it stands in for', unpairedCalls($bag->all()) === [],
    json_encode(unpairedCalls($bag->all())));

// Varying arguments is work, not circling, and must not trip the guard.
$working = new ScriptedPlatform([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'count', 'arguments' => ['what' => 'one']]]]],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'count', 'arguments' => ['what' => 'two']]]]],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'count', 'arguments' => ['what' => 'three']]]]],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'count', 'arguments' => ['what' => 'four']]]]],
    ['role' => 'assistant', 'content' => 'fini'],
]);
$tool = new CountTool();
[$result] = loopWith($working, [$tool]);
check('four different calls in a row are work, not a loop', $result->content === 'fini' && $tool->ran === 4, $result->content);

// ---- an empty reply is not an answer ---------------------------------------
// Seen in the benchmark: a reasoning model thought, then replied with nothing,
// and the turn ended in silence as if that were the answer.
$flaky = new ScriptedPlatform([
    ['role' => 'assistant', 'content' => ''],
    ['role' => 'assistant', 'content' => 'the quantity is left out'],
]);
[$result, $bag] = loopWith($flaky);
check('an empty reply is asked again once', $result->content === 'the quantity is left out' && $flaky->calls === 2,
    "{$result->content} / {$flaky->calls}");
check('and leaves no empty answer in the history', !in_array('', array_column(
    array_filter($bag->all(), fn($m) => ($m['role'] ?? '') === 'assistant'), 'content'), true));

$mute = new ScriptedPlatform([
    ['role' => 'assistant', 'content' => ''],
    ['role' => 'assistant', 'content' => "  \n"],
    ['role' => 'assistant', 'content' => 'jamais atteint'],
]);
[$result] = loopWith($mute);
check('two empty replies in a row stop the turn, and say so',
    str_contains($result->content, 'two empty replies') && $mute->calls === 2, "{$result->content} / {$mute->calls}");

// ---- a history every backend accepts ---------------------------------------
// Ollama never matched a tool result to its call, so nothing kept the link.
// Hosted APIs all do, and reject the whole history when they cannot — on that
// request and on every one after it, since the session keeps the history.
$tool = new CountTool();
$scripted = new ScriptedPlatform([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['function' => ['name' => 'count', 'arguments' => ['what' => 'one']]],
        ['function' => ['name' => 'count', 'arguments' => ['what' => 'two']]],
    ]],
    ['role' => 'assistant', 'content' => 'fini'],
]);
[$result, $bag] = loopWith($scripted, [$tool]);

$calls = array_merge(...array_map(fn($m) => $m['tool_calls'] ?? [], $bag->all()));
check('a call that arrived without an id is given one',
    count($calls) === 2 && preg_match('/^[A-Za-z0-9]{9}$/', $calls[0]['id'] ?? '') === 1, json_encode($calls));
check('two calls in one reply get different ids', ($calls[0]['id'] ?? 'x') !== ($calls[1]['id'] ?? 'x'));
check('each result names the call it answers', unpairedCalls($bag->all()) === [], json_encode(unpairedCalls($bag->all())));
check('and the backend is sent the history that way', unpairedCalls($scripted->sawMessages) === [],
    json_encode(unpairedCalls($scripted->sawMessages)));

// A backend that chose its own id will check the result against that one.
// And one that streams arguments as JSON text must not leave a string where
// the contract promises an array — it would go back out as a string.
$tool = new CountTool();
$scripted = new ScriptedPlatform([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['id' => 'call_abc123', 'function' => ['name' => 'count', 'arguments' => '{"what":"text"}']],
    ]],
    ['role' => 'assistant', 'content' => 'fini'],
]);
[, $bag] = loopWith($scripted, [$tool]);

$call = array_merge(...array_map(fn($m) => $m['tool_calls'] ?? [], $bag->all()))[0] ?? [];
check('an id chosen by the backend is kept', ($call['id'] ?? null) === 'call_abc123', json_encode($call));
check('arguments streamed as JSON text are stored decoded',
    ($call['function']['arguments'] ?? null) === ['what' => 'text'], json_encode($call));
check('and still reach the tool', $tool->ran === 1
    && str_contains(json_encode($bag->all(), JSON_UNESCAPED_UNICODE), 'counted: text'));

// A turn that dies between writing the calls and answering them. The session
// survives the exception and keeps the history, so the history has to survive
// it too.
$scripted = new ScriptedPlatform([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['function' => ['name' => 'count', 'arguments' => ['what' => 'one']]],
        ['function' => ['name' => 'count', 'arguments' => ['what' => 'two']]],
    ]],
]);
[$thrown, $bag] = loopWith($scripted, [new CountTool()], ExplodingToolbox::class);

check('an exception mid-turn still reaches the caller', $thrown instanceof RuntimeException, get_debug_type($thrown));
check('but leaves no call unanswered in the history', unpairedCalls($bag->all()) === [],
    json_encode(unpairedCalls($bag->all())));
check('and each stub says the call never ran',
    substr_count(json_encode($bag->all(), JSON_UNESCAPED_UNICODE), 'Not run') === 2,
    json_encode($bag->all(), JSON_UNESCAPED_UNICODE));

$tidy = new MessageBag();
$tidy->user('x');
$tidy->add(['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'abcdefghi', 'function' => ['name' => 'count', 'arguments' => []]]]]);
$tidy->tool('count', 'ok', 'abcdefghi');
check('a history with nothing open is left as it is', $tidy->closeOpenToolCalls('stub') === 0 && $tidy->count() === 3);

// ---- two backends, one interface --------------------------------------------
// Everything is handed one PlatformInterface and never learns which backend is
// behind it. Switching is one call, and nothing can be left talking to the
// backend the others moved away from.
$local = new OllamaPlatform(new MockHttpClient(new MockResponse('{"message":{"content":"local"},"done":true}')), 'http://x', 'm');
$remote = new OpenAiCompatiblePlatform(
    new MockHttpClient(new MockResponse(['data: ' . json_encode(['choices' => [['delta' => ['content' => 'en ligne']]]]) . "\n\n", "data: [DONE]\n\n"])),
    'https://api.example.com/v1',
    'distant',
);
$switch = new SwitchablePlatform($local, $remote);

check('the switch starts on the API', $switch->backend() === Backend::Api && $switch->name() === 'api.example.com', $switch->name());
check('and requests go to it', ($switch->stream([])['content'] ?? '') === 'en ligne');

$switch->switchTo(Backend::Ollama);
check('switching moves every call at once', $switch->name() === 'Ollama' && ($switch->stream([])['content'] ?? '') === 'local');

$switch->useModel('autre:7b', 16384);
check('a model change reaches only the backend in force',
    $local->modelName() === 'autre:7b' && $remote->modelName() === 'distant',
    $local->modelName() . ' / ' . $remote->modelName());

// ---- the container hands out the interface ---------------------------------
$kernel = new Kernel('dev', true);
$kernel->boot();
$command = $kernel->getContainer()->get('console.command_loader')->get('sherpa');
if ($command instanceof \Symfony\Component\Console\Command\LazyCommand) {
    $command = $command->getCommand();
}
$injected = (new ReflectionProperty($command::class, 'platform'))->getValue($command);
check('the container injects the interface, resolved to the backend switch',
    $injected instanceof PlatformInterface && $injected instanceof SwitchablePlatform, get_debug_type($injected));
check('and the API is the backend in force until told otherwise',
    $injected->backend() === Backend::Api, $injected->backend()->value);

// The loop got the same thing: the alias is one service, not one per injection.
$loop = (new ReflectionProperty($command::class, 'agentLoop'))->getValue($command);
check('and the loop was handed that very instance',
    (new ReflectionProperty(AgentLoop::class, 'platform'))->getValue($loop) === $injected);

if ($realHome !== null) {
    $_SERVER['HOME'] = $realHome;
}
exec('rm -rf ' . escapeshellarg($home));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
