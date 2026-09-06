<?php
// Ctrl+C cancels the turn, not the session — and leaves the conversation in a
// state the next request can actually be built from.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\AgentLoop;
use App\Agent\ContextBudget;
use App\Agent\HistoryCompactor;
use App\Agent\MessageBag;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Permission\PermissionBroker;
use App\Permission\ProjectPermissions;
use App\Permission\SessionPermissions;
use App\Platform\OllamaPlatform;
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
        echo "        ", substr($detail, 0, 240), "\n";
    }
}

// ProjectStore writes under $HOME; keep it away from the real config.
$realHome = $_SERVER['HOME'] ?? null;
$home = sys_get_temp_dir() . '/sherpa-int-' . bin2hex(random_bytes(4));
mkdir($home . '/.config/sherpa/projects', 0777, true);
$_SERVER['HOME'] = $home;

// ---- the signal path, driven by real signals -------------------------------
$interrupt = new Interrupt();
$quits = 0;
$interrupt->install(function () use (&$quits) { $quits++; });

check('nothing is requested to start with', !$interrupt->requested());

// The handler must stay installed even outside a turn: Sherpa runs as PID 1 in
// its container, and the kernel applies no default action to a signal PID 1 has
// no handler for. SIG_DFL there is not "quit", it is "ignore".
check('a handler is installed, not left to SIG_DFL', is_callable(pcntl_signal_get_handler(SIGINT)));

// Idle: Ctrl+C means quit, as it does at any shell prompt.
posix_kill(getmypid(), SIGINT);
check('an idle Ctrl+C quits', $quits === 1);
check('and raises no cancellation', !$interrupt->requested());

// In a turn: Ctrl+C means cancel, and the session survives.
$interrupt->beginTurn();
posix_kill(getmypid(), SIGINT);
check('Ctrl+C during a turn does not quit', $quits === 1);
check('it requests cancellation instead', $interrupt->requested());

// Cancellation is cooperative, so a second press is the way out of a turn that
// never reaches a checkpoint.
posix_kill(getmypid(), SIGINT);
check('a second Ctrl+C in the same turn quits', $quits === 2);

$interrupt->endTurn();
$interrupt->beginTurn();
check('beginTurn clears the previous request', !$interrupt->requested());

posix_kill(getmypid(), SIGINT);
check('and the counter reset with it', $quits === 2);
$interrupt->endTurn();

// ---- the platform stops streaming ------------------------------------------
$chunks = [
    '{"message":{"content":"une "}}' . "\n",
    '{"message":{"content":"réponse "}}' . "\n",
    '{"message":{"content":"complète"},"done":true}',
];

$quiet = new Interrupt();
$platform = new OllamaPlatform(new MockHttpClient(new MockResponse($chunks)), 'http://x', 'm', 32768, -1.0, $quiet);
check('without cancellation the whole reply arrives', $platform->stream([])['content'] === 'une réponse complète');

$cancelling = new Interrupt();
$cancelling->request();
$platform = new OllamaPlatform(new MockHttpClient(new MockResponse($chunks)), 'http://x', 'm', 32768, -1.0, $cancelling);
$tokens = [];
$msg = $platform->stream([], [], function (string $t) use (&$tokens) { $tokens[] = $t; });
check('a cancelled stream yields no content', $msg['content'] === '', json_encode($msg));
check('and emits no tokens', $tokens === [], json_encode($tokens));

// ---- the loop --------------------------------------------------------------

/** Cancels the turn from inside a tool, so the loop is interrupted mid-batch. */
#[AsTool(name: 'trip', description: 'Demande l\'annulation', permission: Permission::AUTO)]
final class TripTool
{
    public function __construct(private readonly Interrupt $interrupt) {}

    public function __invoke(#[Param('inutilisé')] string $note = ''): string
    {
        $this->interrupt->request();

        return 'annulation demandée';
    }
}

#[AsTool(name: 'after', description: 'Ne devrait jamais tourner', permission: Permission::AUTO)]
final class AfterTool
{
    public bool $ran = false;

    public function __invoke(#[Param('inutilisé')] string $note = ''): string
    {
        $this->ran = true;

        return 'a tourné';
    }
}

function loopWith(Interrupt $interrupt, array $tools, array $chunks): array
{
    $platform = new OllamaPlatform(new MockHttpClient(new MockResponse($chunks)), 'http://x', 'm', 32768, -1.0, $interrupt);
    $budget = new ContextBudget();
    $broker = new PermissionBroker(
        new SessionPermissions(),
        new ConfirmOverlay(new Terminal(), new FileWriteTool(), new FilePatchTool(), new ShellExecTool()),
        new ProjectPermissions(new ProjectStore(new StackDetector())),
    );

    $loop = new AgentLoop(
        $platform,
        new Toolbox($tools),
        $broker,
        new ChatPane(new Terminal(), new MarkdownRenderer()),
        $budget,
        new HistoryCompactor($platform, $budget),
        $interrupt,
    );

    $bag = new MessageBag();
    $bag->system('SYSTEM');
    $bag->user('fais quelque chose');

    ob_start();
    $result = $loop->run($bag);
    ob_end_clean();

    return [$result, $bag];
}

$twoCalls = ['{"message":{"role":"assistant","content":"","tool_calls":['
    . '{"function":{"name":"trip","arguments":{"note":"x"}}},'
    . '{"function":{"name":"after","arguments":{"note":"y"}}}'
    . ']},"done":true}'];

$interrupt = new Interrupt();
$after = new AfterTool();
[$result, $bag] = loopWith($interrupt, [new TripTool($interrupt), $after], $twoCalls);

check('the loop reports the turn as interrupted', $result->interrupted);
check('it did not report hitting the iteration cap', !$result->maxIterationsReached);
check('the tool after the cancellation never ran', !$after->ran);

// The property that matters: an assistant message carrying tool_calls must be
// followed by one tool message per call, or the next request is malformed.
$messages = $bag->all();
$assistantCalls = 0;
$toolMessages = 0;
foreach ($messages as $m) {
    $assistantCalls += count($m['tool_calls'] ?? []);
    $toolMessages += ($m['role'] ?? '') === 'tool' ? 1 : 0;
}
check(
    'every tool call still has a matching tool message',
    $assistantCalls === $toolMessages && $assistantCalls === 2,
    "calls={$assistantCalls} tool messages={$toolMessages}",
);
check(
    'the cancelled call is recorded as interrupted',
    str_contains(json_encode($messages, JSON_UNESCAPED_UNICODE), 'Interrompu'),
    json_encode($messages, JSON_UNESCAPED_UNICODE),
);

// Cancelled before the request even goes out.
$early = new Interrupt();
$early->request();
[$result, $bag] = loopWith($early, [], ['{"message":{"content":"salut"},"done":true}']);
check('a turn cancelled up front is reported interrupted', $result->interrupted);
check('and writes no assistant reply to history', !in_array('assistant', array_column($bag->all(), 'role'), true), json_encode($bag->all(), JSON_UNESCAPED_UNICODE));

// A normal turn is untouched by any of this.
$calm = new Interrupt();
[$result, $bag] = loopWith($calm, [], ['{"message":{"content":"salut"},"done":true}']);
check('an uninterrupted turn is not marked interrupted', !$result->interrupted);
check('and returns the reply', $result->content === 'salut', $result->content);

// ---- cleanup ---------------------------------------------------------------
if ($realHome !== null) {
    $_SERVER['HOME'] = $realHome;
}
exec('rm -rf ' . escapeshellarg($home));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
