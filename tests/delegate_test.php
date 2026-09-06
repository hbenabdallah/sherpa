<?php
// delegate: a sub-agent that reads in its own context and reports back —
// against a scripted model, on a small project on disk.

require dirname(__DIR__) . '/vendor/autoload.php';

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

    public function __construct(private array $replies, private ?Interrupt $cancel = null) {}

    public function stream(array $messages, array $tools = [], ?callable $onToken = null, ?callable $onWait = null): array
    {
        $this->seen[] = ['messages' => $messages, 'tools' => array_map(fn($t) => $t['function']['name'], $tools)];
        $reply = array_shift($this->replies) ?? ['role' => 'assistant', 'content' => 'done'];
        if (($reply['content'] ?? '') !== '' && $onToken !== null) {
            $onToken($reply['content']);
        }
        if (($reply['cancel'] ?? false) && $this->cancel !== null) {
            $this->cancel->request();
        }
        unset($reply['cancel']);

        return $reply;
    }
    public function lastUsage(): ?array { return null; }
    public function lastTimings(): ?array { return null; }
    public function residency(): ?ModelResidency { return null; }
    public function modelName(): string { return 'scripted'; }
    public function contextWindow(): int { return 32768; }
    public function catalogue(): array { return []; }
    public function describeModel(string $model): ?ModelInfo { return null; }
    public function useModel(string $model, int $contextWindow): void {}
    public function name(): string { return 'scripted'; }
    public function isAvailable(): bool { return true; }
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
        $interrupt ?? new Interrupt(),
        $paths,
        new ListDirTool($paths),
        new FileFindTool($paths),
        new ProjectGrepTool($paths),
        new FileReadTool($paths),
        // Not used here, and it wants a whole open project: built bare.
        (new ReflectionClass(DocSearchTool::class))->newInstanceWithoutConstructor(),
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
check('its search really ran on the project',
    str_contains(json_encode($model->seen[1]['messages']), 'Invoice.php:4'), json_encode(end($model->seen[1]['messages'])));
check('it starts from the task alone, not the conversation that asked',
    count($model->seen[0]['messages']) === 2 && $model->seen[0]['messages'][1]['content'] === 'Where is the invoice subtotal computed?');
check('it is told it can only read', str_contains($model->seen[0]['messages'][0]['content'], 'You can only read'));

$tools = $model->seen[0]['tools'];
sort($tools);
check('it is given the tools that read, and only those',
    $tools === ['doc_search', 'file_find', 'file_read', 'list_dir', 'memory_recall', 'project_grep'], json_encode($tools));
check('nothing that writes, runs, leaves the machine or delegates again',
    array_intersect($tools, ['file_write', 'file_patch', 'shell_exec', 'web_fetch', 'delegate', 'todo_write']) === []);

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
try { delegate($model, $paths, $memory)('  '); } catch (RuntimeException) { $threw = true; }
check('an empty task is refused', $threw);

// ---- the tool itself ------------------------------------------------------------
$def = (new Toolbox([delegate($model, $paths, $memory)]))->find('delegate');
check('delegate runs without asking: it only reads', $def?->permission === Permission::AUTO);

// ---- a sub-agent is no rewrite of the main conversation (Anthropic) ---------------
$sent = [];
$sse = fn() => new MockResponse([
    "data: " . json_encode(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 1, 'output_tokens' => 0]]]) . "\n\n",
    "data: " . json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]) . "\n\n",
    "data: " . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']]) . "\n\n",
    "data: " . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]]) . "\n\n",
], ['response_headers' => ['content-type' => 'text/event-stream']]);
$claude = new OpenAiCompatiblePlatform(new MockHttpClient(function ($m, $u, $o) use (&$sent, $sse) { $sent[] = $o['body']; return $sse(); }), 'https://api.anthropic.com/v1', 'claude-sonnet-5', 'k');
$main = [
    ['role' => 'system', 'content' => 'MAIN'],
    ['role' => 'user', 'content' => 'q'],
    ['role' => 'assistant', 'content' => 'a', AnthropicWire::THINKING => [['type' => 'thinking', 'thinking' => 't', 'signature' => 'MAIN-SIG']]],
    ['role' => 'user', 'content' => 'q2'],
];
$claude->stream($main, [], fn() => null);
$claude->stream([['role' => 'system', 'content' => 'SUB'], ['role' => 'user', 'content' => 'task']], [], fn() => null);
$main[] = ['role' => 'assistant', 'content' => 'b'];
$main[] = ['role' => 'user', 'content' => 'q3'];
$claude->stream($main, [], fn() => null);
check("a sub-agent's requests in between do not strip the main conversation's thinking", str_contains($sent[2], 'MAIN-SIG'), $sent[2]);

exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
