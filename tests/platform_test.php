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
use App\Kernel;
use App\Permission\PermissionBroker;
use App\Permission\ProjectPermissions;
use App\Permission\SessionPermissions;
use App\Platform\OllamaPlatform;
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

    public function stream(array $messages, array $tools = [], ?callable $onToken = null): array
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
    public string $model = 'scripté';
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

#[AsTool(name: 'compter', description: 'Compte quelque chose', permission: Permission::AUTO)]
final class CountTool
{
    public int $ran = 0;

    public function __invoke(#[Param('quoi')] string $quoi = ''): string
    {
        $this->ran++;

        return "compté: {$quoi}";
    }
}

function loopWith(PlatformInterface $platform, array $tools = []): array
{
    $budget = new ContextBudget(contextWindow: 32768);
    $speed = new InferenceSpeed();
    $loop = new AgentLoop(
        $platform,
        new Toolbox($tools),
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
    $result = $loop->run($bag);
    ob_end_clean();

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
$scripted = new ScriptedPlatform([['role' => 'assistant', 'content' => 'voici la réponse']]);
[$result, $bag] = loopWith($scripted);

check('the loop runs a turn through a foreign backend', $result->content === 'voici la réponse', $result->content);
check('and the reply is written to history', in_array('assistant', array_column($bag->all(), 'role'), true));
check('the backend was handed the conversation', ($scripted->sawMessages[0]['content'] ?? null) === 'SYSTEM', json_encode($scripted->sawMessages));

// Tool calling too: this is where the wire format would leak if it had.
$tool = new CountTool();
$scripted = new ScriptedPlatform([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'compter', 'arguments' => ['quoi' => 'les moutons']]]]],
    ['role' => 'assistant', 'content' => 'trois moutons'],
]);
[$result, $bag] = loopWith($scripted, [$tool]);

check('a tool call round-trips through the interface', $tool->ran === 1 && $result->content === 'trois moutons', $result->content);
check('the tool result went back to the backend',
    str_contains(json_encode($scripted->sawMessages, JSON_UNESCAPED_UNICODE), 'compté: les moutons'),
    json_encode($scripted->sawMessages, JSON_UNESCAPED_UNICODE));
check('and the tool schemas were passed along', ($scripted->sawTools[0]['function']['name'] ?? null) === 'compter', json_encode($scripted->sawTools));

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
    'tool_calls' => [['function' => ['name' => 'compter', 'arguments' => ['quoi' => 'encore']]]],
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

// Varying arguments is work, not circling, and must not trip the guard.
$working = new ScriptedPlatform([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'compter', 'arguments' => ['quoi' => 'un']]]]],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'compter', 'arguments' => ['quoi' => 'deux']]]]],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'compter', 'arguments' => ['quoi' => 'trois']]]]],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'compter', 'arguments' => ['quoi' => 'quatre']]]]],
    ['role' => 'assistant', 'content' => 'fini'],
]);
$tool = new CountTool();
[$result] = loopWith($working, [$tool]);
check('four different calls in a row are work, not a loop', $result->content === 'fini' && $tool->ran === 4, $result->content);

// ---- the container hands out the interface ---------------------------------
$kernel = new Kernel('dev', true);
$kernel->boot();
$command = $kernel->getContainer()->get('console.command_loader')->get('sherpa');
if ($command instanceof \Symfony\Component\Console\Command\LazyCommand) {
    $command = $command->getCommand();
}
$injected = (new ReflectionProperty($command::class, 'platform'))->getValue($command);
check('the container injects the interface, resolved to Ollama',
    $injected instanceof PlatformInterface && $injected instanceof OllamaPlatform, get_debug_type($injected));

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
