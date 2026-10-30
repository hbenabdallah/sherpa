<?php

declare(strict_types=1);

namespace App\Tests\DelegateTest;

use App\Agent\Lanes;
use Fiber;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;

// delegate: a sub-agent that reads in its own context and reports back —
// against a scripted model, on a small project on disk.

require __DIR__ . '/../vendor/autoload.php';

use App\Agent\AgentLoop;
use App\Agent\ContextBudget;
use App\Agent\HistoryCompactor;
use App\Agent\MessageBag;
use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Memory\MemoryStore;
use App\Permission\PermissionBroker;
use App\Permission\ProjectPermissions;
use App\Permission\SessionPermissions;
use App\Platform\AnthropicWire;
use App\Platform\ModelInfo;
use App\Platform\ModelResidency;
use App\Platform\OpenAiCompatiblePlatform;
use App\Platform\PlatformInterface;
use App\Project\ProjectPathResolver;
use App\Project\ProjectStore;
use App\Project\StackDetector;
use App\Runtime\Interrupt;
use App\Tool\DelegateTool;
use App\Tool\DocSearchTool;
use App\Tool\FileFindTool;
use App\Tool\FilePatchTool;
use App\Tool\FileReadTool;
use App\Tool\FileWriteTool;
use App\Tool\ListDirTool;
use App\Tool\MemoryRecallTool;
use App\Tool\ProjectGrepTool;
use App\Tool\ShellExecTool;
use App\TUI\ChatPane;
use App\TUI\ConfirmOverlay;
use App\TUI\MarkdownRenderer;
use App\TUI\SubagentPane;
use App\TUI\Terminal;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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

/** A model that plays back replies, and keeps what it was sent. */
final class Scripted implements PlatformInterface
{
    public array $seen = [];

    /** @var list<int> what concurrency() answers, one call after another; the last one holds */
    public array $concurrency = [3];

    public function __construct(private array $replies, private readonly ?Interrupt $cancel = null) {}

    public function concurrency(): int
    {
        return count($this->concurrency) > 1 ? array_shift($this->concurrency) : $this->concurrency[0];
    }

    public function stream(array $messages, array $tools = [], ?callable $onToken = null, ?callable $onWait = null): array
    {
        $this->seen[] = ['messages' => $messages, 'tools' => array_map(fn(array $t): mixed => $t['function']['name'], $tools)];
        $reply = array_shift($this->replies) ?? ['role' => 'assistant', 'content' => 'done'];
        if (($reply['content'] ?? '') !== '' && $onToken !== null) {
            $onToken($reply['content']);
        }
        if (($reply['cancel'] ?? false) && $this->cancel instanceof Interrupt) {
            $this->cancel->request();
        }
        unset($reply['cancel']);

        return $reply;
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
        return 'scripted';
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
        return 'scripted';
    }

    public function isAvailable(): bool
    {
        return true;
    }
}

$root = sys_get_temp_dir() . '/sherpa-delegate-' . bin2hex(random_bytes(4));
mkdir("{$root}/src/Billing", 0777, true);
file_put_contents("{$root}/src/Billing/Invoice.php", "<?php\nfinal class Invoice\n{\n    public function subtotal(): int { return 0; }\n}\n");
$paths = new ProjectPathResolver();
$paths->setRoot($root);
$memory = new MemoryStore();
$memory->open(':memory:');

function delegate(PlatformInterface $platform, ProjectPathResolver $paths, MemoryStore $memory, ?Interrupt $interrupt = null): DelegateTool
{
    return new DelegateTool(
        $platform,
        new PermissionBroker(new SessionPermissions(), new ConfirmOverlay(new Terminal(), new FileWriteTool(), new FilePatchTool(), new ShellExecTool()), new ProjectPermissions(new ProjectStore(new StackDetector()))),
        new ChatPane(new Terminal(), new MarkdownRenderer()),
        $interrupt ?? new Interrupt(),
        $paths,
        new ListDirTool($paths),
        new FileFindTool($paths),
        new ProjectGrepTool($paths),
        new FileReadTool($paths),
        // Not used here, and it wants a whole open project: built bare.
        new ReflectionClass(DocSearchTool::class)->newInstanceWithoutConstructor(),
        new MemoryRecallTool($memory),
    );
}

$model = new Scripted([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'project_grep', 'arguments' => ['pattern' => 'subtotal']]]]],
    ['role' => 'assistant', 'content' => 'subtotal() is in src/Billing/Invoice.php:4, and returns 0.'],
]);
ob_start();
$report = delegate($model, $paths, $memory)('Where is the invoice subtotal computed?');
$screen = Terminal::plain(ob_get_clean());

check('the sub-agent searches, and its report comes back', str_contains($report, 'src/Billing/Invoice.php:4'), $report);
check('the report says it is one, and how many steps it took', str_starts_with($report, 'Sub-agent report (2 steps)'), $report);
check(
    'its search really ran on the project',
    str_contains(json_encode($model->seen[1]['messages']), 'Invoice.php:4'),
    json_encode(end($model->seen[1]['messages'])),
);
check(
    'it starts from the task alone, not the conversation that asked',
    count($model->seen[0]['messages']) === 2 && $model->seen[0]['messages'][1]['content'] === 'Where is the invoice subtotal computed?',
);
check('it is told it can only read', str_contains($model->seen[0]['messages'][0]['content'], 'You can only read'));

$tools = $model->seen[0]['tools'];
sort($tools);
check(
    'it is given the tools that read, and only those',
    $tools === ['doc_search', 'file_find', 'file_read', 'list_dir', 'memory_recall', 'project_grep'],
    json_encode($tools),
);
check(
    'nothing that writes, runs, leaves the machine or delegates again',
    array_intersect($tools, ['file_write', 'file_patch', 'shell_exec', 'web_fetch', 'delegate', 'todo_write']) === [],
);

check('on screen, one short line per tool it uses', str_contains($screen, '↳ project_grep subtotal'), $screen);
check('and not its reply, which is for the agent that asked', !str_contains($screen, 'returns 0'), $screen);

// Ctrl+C reaches the sub-agent too.
$interrupt = new Interrupt();
$interrupt->beginTurn();
$cut = new Scripted([['role' => 'assistant', 'content' => 'half', 'cancel' => true]], $interrupt);
ob_start();
$report = delegate($cut, $paths, $memory, $interrupt)('anything');
ob_end_clean();
check('an interrupted sub-agent says so', str_contains($report, 'interrupted'), $report);

$threw = false;

try {
    delegate($model, $paths, $memory)('  ');
} catch (RuntimeException) {
    $threw = true;
}
check('an empty task is refused', $threw);

// ---- the tool itself ------------------------------------------------------------
$def = new Toolbox([delegate($model, $paths, $memory)])->find('delegate');
check('delegate runs without asking: it only reads', $def?->permission === Permission::AUTO);

// ---- a sub-agent is no rewrite of the main conversation (Anthropic) ---------------
$sent = [];
$sse = fn(): MockResponse => new MockResponse([
    "data: " . json_encode(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 1, 'output_tokens' => 0]]]) . "\n\n",
    "data: " . json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]) . "\n\n",
    "data: " . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']]) . "\n\n",
    "data: " . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]]) . "\n\n",
], ['response_headers' => ['content-type' => 'text/event-stream']]);
$claude = new OpenAiCompatiblePlatform(new MockHttpClient(function ($m, $u, array $o) use (&$sent, $sse): MockResponse {
    $sent[] = $o['body'];

    return $sse();
}), 'https://api.anthropic.com/v1', 'claude-sonnet-5', 'k');
$main = [
    ['role' => 'system', 'content' => 'MAIN'],
    ['role' => 'user', 'content' => 'q'],
    ['role' => 'assistant', 'content' => 'a', AnthropicWire::THINKING => [['type' => 'thinking', 'thinking' => 't', 'signature' => 'MAIN-SIG']]],
    ['role' => 'user', 'content' => 'q2'],
];
$claude->stream($main, [], fn(): null => null);
$claude->stream([['role' => 'system', 'content' => 'SUB'], ['role' => 'user', 'content' => 'task']], [], fn(): null => null);
$main[] = ['role' => 'assistant', 'content' => 'b'];
$main[] = ['role' => 'user', 'content' => 'q3'];
$claude->stream($main, [], fn(): null => null);
check("a sub-agent's requests in between do not strip the main conversation's thinking", str_contains($sent[2], 'MAIN-SIG'), $sent[2]);

// ---- sub-agents asked for together run together -----------------------------------

/** What each request took, as SwitchablePlatform reads it: right after, in the same Fiber. */
final class Counted implements PlatformInterface
{
    /** @var list<array<string, int>|null> */
    public array $usages = [];

    public function __construct(private readonly PlatformInterface $inner) {}

    public function stream(array $messages, array $tools = [], ?callable $onToken = null, ?callable $onWait = null): array
    {
        $reply = $this->inner->stream($messages, $tools, $onToken, $onWait);
        $this->usages[] = $this->inner->lastUsage();

        return $reply;
    }

    public function concurrency(): int
    {
        return $this->inner->concurrency();
    }

    public function lastUsage(): ?array
    {
        return $this->inner->lastUsage();
    }

    public function lastTimings(): ?array
    {
        return $this->inner->lastTimings();
    }

    public function residency(): ?ModelResidency
    {
        return null;
    }

    public function modelName(): string
    {
        return 'm';
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
        return 'counted';
    }

    public function isAvailable(): bool
    {
        return true;
    }
}

$port = 20000 + (getmypid() + 7) % 10000;
$server = proc_open(['env', 'PHP_CLI_SERVER_WORKERS=4', PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/subagent_provider.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(100_000);
}

$interrupt = new Interrupt();
$sub = new Counted(new OpenAiCompatiblePlatform(HttpClient::create(), "http://127.0.0.1:{$port}/v1", 'm', 'k', 32768, 30.0, $interrupt));
$asks = fn(array $tasks): Scripted => new Scripted([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => array_map(
        fn(string $task, int $i): array => ['id' => "d{$i}", 'function' => ['name' => 'delegate', 'arguments' => ['task' => $task]]],
        $tasks,
        array_keys($tasks),
    )],
    ['role' => 'assistant', 'content' => 'all read'],
]);

function mainLoop(PlatformInterface $main, DelegateTool $delegate, Interrupt $interrupt): AgentLoop
{
    $budget = new ContextBudget();

    return new AgentLoop(
        $main,
        new Toolbox([$delegate]),
        new PermissionBroker(new SessionPermissions(), new ConfirmOverlay(new Terminal(), new FileWriteTool(), new FilePatchTool(), new ShellExecTool()), new ProjectPermissions(new ProjectStore(new StackDetector()))),
        new ChatPane(new Terminal(), new MarkdownRenderer()),
        $budget,
        new HistoryCompactor($main, $budget),
        $interrupt,
    );
}

$bag = new MessageBag();
$bag->user('read three things');
$started = microtime(true);
ob_start();
$result = mainLoop($asks(['alpha', 'beta-beta', 'gamma-gamma-gamma']), delegate($sub, $paths, $memory, $interrupt), $interrupt)->run($bag);
$screen = Terminal::plain((string) ob_get_clean());
$took = microtime(true) - $started;

$reports = array_values(array_filter($bag->all(), fn(array $m): bool => $m['role'] === 'tool'));
check('three sub-agents of a second each take about a second, not three', $took < 1.6, sprintf('%.1f s', $took));
check(
    'each report comes back to its own call, in the order asked',
    array_column($reports, 'tool_call_id') === ['d0', 'd1', 'd2']
    && str_contains($reports[0]['content'], 'report on alpha')
    && str_contains($reports[1]['content'], 'report on beta-beta')
    && str_contains($reports[2]['content'], 'report on gamma-gamma-gamma'),
    json_encode($reports),
);
check('the turn goes on once all are back', $result->content === 'all read', $result->content);
$prompts = array_map(fn(array $u) => $u['prompt'] ?? null, $sub->usages);
sort($prompts);
check("each request's token count is its own, not another's finishing beside it", $prompts === [5, 9, 17], json_encode($sub->usages));
check(
    'the three calls are shown before any result, numbered in the order asked',
    preg_match('/Tool: \\[1\\] delegate.*Tool: \\[2\\] delegate.*Tool: \\[3\\] delegate/s', substr($screen, 0, (int) strpos($screen, '✓'))) === 1,
    $screen,
);

// Their lines carry the same number: they interleave, and would be anyone's.
$pane = new SubagentPane(new Terminal(), new MarkdownRenderer());
$lines = '';
foreach ([1, 2] as $n) {
    $fiber = new Fiber(function () use ($pane): string|false {
        ob_start();
        $pane->showToolCall('project_grep', ['pattern' => 'subtotal']);

        return ob_get_clean();
    });
    Lanes::assign($fiber, $n);
    $fiber->start();
    $lines .= Terminal::plain((string) $fiber->getReturn());
}
check("each sub-agent's lines carry its number", str_contains($lines, '↳ [1] project_grep subtotal') && str_contains($lines, '↳ [2] project_grep subtotal'), $lines);

// A provider that takes one at a time gets them one at a time, shown as before.
$bag = new MessageBag();
$bag->user('read two things');
$one = $asks(['alpha', 'beta-beta']);
$one->concurrency = [1];
$started = microtime(true);
ob_start();
mainLoop($one, delegate($sub, $paths, $memory, $interrupt), $interrupt)->run($bag);
$screen = Terminal::plain((string) ob_get_clean());
$took = microtime(true) - $started;
check('a provider that takes one at a time gets them one after the other', $took >= 1.9, sprintf('%.1f s', $took));
check('each shown with its result under it, and no number', substr_count(substr($screen, 0, (int) strpos($screen, '✓')), 'Tool: delegate') === 1 && !str_contains($screen, '[1]'), $screen);

// A rate limit met while they run lowers how many go on at once.
$bag = new MessageBag();
$bag->user('read three things');
$lowered = $asks(['alpha', 'beta-beta', 'gamma-gamma-gamma']);
$lowered->concurrency = [3, 3, 3, 1];
$started = microtime(true);
ob_start();
mainLoop($lowered, delegate($sub, $paths, $memory, $interrupt), $interrupt)->run($bag);
ob_end_clean();
$took = microtime(true) - $started;
check('the third waits for the first two once the provider says slow down', $took >= 1.9 && $took < 2.6, sprintf('%.1f s', $took));
check('and all three still report', substr_count(json_encode($bag->all()), 'report on ') === 3);

// One alone runs as before, outside any Fiber.
$bag = new MessageBag();
$bag->user('read one thing');
ob_start();
mainLoop($asks(['solo']), delegate($sub, $paths, $memory, $interrupt), $interrupt)->run($bag);
ob_end_clean();
check('a sub-agent asked for alone still reports', str_contains(json_encode($bag->all()), 'report on solo'));

// Ctrl+C stops them all.
$interrupt = new Interrupt();
$interrupt->beginTurn();
$sub = new OpenAiCompatiblePlatform(HttpClient::create(), "http://127.0.0.1:{$port}/v1", 'm', 'k', 32768, 30.0, $interrupt);
$cancelling = new Scripted([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['id' => 'x0', 'function' => ['name' => 'delegate', 'arguments' => ['task' => 'one']]],
        ['id' => 'x1', 'function' => ['name' => 'delegate', 'arguments' => ['task' => 'two']]],
    ]],
]);
$bag = new MessageBag();
$bag->user('read two things');
$timer = $interrupt->meanwhile(null);
pcntl_async_signals(true);
pcntl_signal(SIGALRM, $interrupt->request(...));
pcntl_alarm(1);
$started = microtime(true);
ob_start();
$result = mainLoop($cancelling, delegate($sub, $paths, $memory, $interrupt), $interrupt)->run($bag);
ob_end_clean();
$reports = array_values(array_filter($bag->all(), fn(array $m): bool => $m['role'] === 'tool'));
check(
    'Ctrl+C stops every sub-agent running together',
    $result->interrupted && count($reports) === 2 && str_contains($reports[0]['content'], 'interrupted') && str_contains($reports[1]['content'], 'interrupted'),
    json_encode($reports),
);

proc_terminate($server);
exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
