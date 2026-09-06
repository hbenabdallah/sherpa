<?php
// The slash commands: what a session is steered with. They were three hundred
// lines inside the command that also boots the session and runs its loop, and
// nothing exercised them. Apart, they answer without a terminal and without a
// backend — so this is what that separation bought.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\ContextBudget;
use App\Agent\HistoryCompactor;
use App\Agent\InferenceSpeed;
use App\Agent\MessageBag;
use App\Agent\SystemPromptBuilder;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Command\ModelSession;
use App\Command\SlashCommands;
use App\Config\Backend;
use App\Config\MachineConfig;
use App\Config\ModelProfile;
use App\Config\ModelResolver;
use App\Mcp\McpConfig;
use App\Mcp\McpRegistry;
use App\Memory\ContextStore;
use App\Memory\MemoryStore;
use App\Permission\ProjectPermissions;
use App\Permission\SessionPermissions;
use App\Platform\OllamaPlatform;
use App\Platform\OpenAiCompatiblePlatform;
use App\Platform\SwitchablePlatform;
use App\Project\DockerConfig;
use App\Project\Project;
use App\Project\ProjectPathResolver;
use App\Project\ProjectQuestions;
use App\Project\ProjectStore;
use App\Project\WorkingProject;
use App\Rag\Chunker;
use App\Rag\DocIndex;
use App\Rag\DocSearch;
use App\Rag\DocSource;
use App\Rag\Ingestor;
use App\Tool\ShellExecTool;
use App\Project\StackDetector;
use App\Skills\SkillRegistry;
use App\TUI\ChatPane;
use App\TUI\LineEditor;
use App\TUI\MarkdownRenderer;
use App\TUI\ModelSelector;
use App\TUI\Terminal;
use App\Session\SessionStore;
use App\Usage\UsageMeter;
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
        echo '        ', substr(Terminal::plain($detail), 0, 400), "\n";
    }
}

#[AsTool(name: 'compter', description: 'Compte quelque chose', permission: Permission::AUTO)]
final class SlashCountTool
{
    public function __invoke(#[Param('what')] string $what = ''): string
    {
        return "counted: {$what}";
    }
}

// A throwaway HOME: a standing grant is written through ProjectStore, which
// reads $_SERVER['HOME'] — these tests must not touch the real projects.yaml.
$realHome = $_SERVER['HOME'] ?? null;
$root = sys_get_temp_dir() . '/sherpa-slash-' . bin2hex(random_bytes(4));
mkdir($root . '/home/.config/sherpa', 0777, true);
$_SERVER['HOME'] = $root . '/home';
mkdir($root . '/projet/src', 0777, true);
mkdir($root . '/config', 0777, true);
file_put_contents($root . '/projet/composer.json', '{"require": {"php": ">=8.3"}}');

$memory = new MemoryStore();
$memory->open($root . '/memory.db');
$context = new ContextStore();
$context->open();
$skills = new SkillRegistry();
$skills->setProjectPath($root . '/projet');
$skills->load();
$mcpConfig = new McpConfig();
$mcpConfig->setPath($root . '/mcp.json');   // no file: no servers
$toolbox = new Toolbox([new SlashCountTool()]);
$budget = new ContextBudget(contextWindow: 32768);
$speed = new InferenceSpeed();
$usage = new UsageMeter();
$chat = new ChatPane(new Terminal(), new MarkdownRenderer());
$editor = new LineEditor();
$detector = new StackDetector();
$prompt = new SystemPromptBuilder($memory, $skills, $detector);
$projects = new ProjectStore($detector);
$permissions = new ProjectPermissions($projects);
$session = new SessionPermissions();

$platform = new SwitchablePlatform(
    new OllamaPlatform(new MockHttpClient(new MockResponse('{"message":{"content":"ok"},"done":true}')), 'http://ollama.test', 'local:7b'),
    new OpenAiCompatiblePlatform(new MockHttpClient(new MockResponse('')), 'https://api.example.com/v1', 'distant/modele'),
);
$platform->switchTo(Backend::Api);

$config = new MachineConfig();
$config->setPath($root . '/config.yaml');
$models = new ModelResolver($config, 'local:7b', 32768);
$sessions = new SessionStore();
$modelSession = new ModelSession(
    $platform, $models, new ModelSelector(new Terminal(), $editor, $platform),
    $budget, $speed, $usage, $chat, $editor, $prompt, $context, $toolbox, $sessions,
);
$modelSession->apply(new ModelProfile('distant/modele', 32768, 'machine config', Backend::Api));

$commands = new SlashCommands(
    $chat, $memory, $context, $skills, new McpRegistry($mcpConfig), $toolbox,
    $budget, $speed, $usage, $platform, $modelSession, $permissions, $session,
    new HistoryCompactor($platform, $budget), $prompt, $sessions,
    $working = new WorkingProject($projects, $memory, $permissions, $sessions, new ProjectPathResolver(), new ShellExecTool()),
    new ProjectQuestions($editor, $projects),
    $docSearch = new DocSearch(new DocIndex(), new Ingestor(new DocSource(), new Chunker()), $working),
);

$project = new Project(
    slug: 'projet', name: 'projet', path: $root . '/projet', memoryDb: $root . '/memory.db',
    docker: new DockerConfig(enabled: false), stack: 'PHP >=8.3',
    createdAt: new DateTimeImmutable(), lastUsedAt: new DateTimeImmutable(),
);
// Opened the way the command opens one: memory, permissions, sessions and the
// tools' root all bound in one place.
$working->open($project);

$bag = new MessageBag();
$bag->system('PROMPT');
$bag->user('bonjour');

/** Run one slash command and return what it printed, and whether it was handled. */
function run(SlashCommands $commands, string $input, MessageBag $bag, Project $project): array
{
    ob_start();
    $handled = $commands->handle($input, $bag, $project);

    return [$handled, Terminal::plain((string) ob_get_clean())];
}
$slash = fn(string $input) => run($commands, $input, $bag, $project);

// ---- a word it does not know is not an answer ------------------------------
[$handled, $out] = $slash('/madeup');
check('an unknown command is not handled, so the caller can say so', !$handled && $out === '', $out);

[$handled, $out] = $slash('/help');
check('/help is handled', $handled);
check('and lists the commands, including the newest', str_contains($out, '/backend') && str_contains($out, '/memory'), $out);

// ---- memory ----------------------------------------------------------------
[, $out] = $slash('/memory');
check('/memory says so when nothing is remembered', str_contains($out, 'Nothing remembered yet'), $out);

[, $out] = $slash('/remember tests php tests/run.php');
check('/remember records a fact', str_contains($out, 'tests'), $out);
check('and the store really holds it', $memory->recall('tests') !== [], json_encode($memory->recall('tests')));

[, $out] = $slash('/memory');
check('/memory then lists it', str_contains($out, 'php tests/run.php'), $out);

[, $out] = $slash('/remember incomplet');
check('/remember without a value explains itself', str_contains($out, 'Usage'), $out);

[, $out] = $slash('/forget tests');
check('/forget removes it', $memory->recall('tests') === [], $out);
[, $out] = $slash('/forget jamais-vu');
check('and says so when there was nothing to remove', str_contains($out, 'No such key'), $out);

// ---- the project and its tools ---------------------------------------------
[, $out] = $slash('/project');
check('/project shows the project in force', str_contains($out, $root . '/projet') && str_contains($out, 'PHP >=8.3'), $out);

[, $out] = $slash('/docs');
check('/docs says what the documentation index holds, even when it is nothing',
    str_contains($out, 'Project documentation') && str_contains($out, 'No document read'), $out);

file_put_contents($root . '/projet/GUIDE.md', "# Guide\n\n## Deployment\n\nWe deploy on Tuesdays.\n");
[, $out] = $slash('/docs when do we deploy');
check('/docs <question> shows exactly what the model would be given', str_contains($out, 'GUIDE.md › Guide › Deployment') && str_contains($out, 'Tuesdays'), $out);

[, $out] = $slash('/docs eval');
check('/docs eval without questions says where they go, and what they look like',
    str_contains($out, '.sherpa/rag-questions.json') && str_contains($out, '"expect"'), $out);

mkdir($root . '/projet/.sherpa', 0777, true);
file_put_contents($root . '/projet/.sherpa/rag-questions.json', json_encode([
    ['kind' => 'procedure', 'q' => 'Which day do we deploy?', 'expect' => [['path' => 'GUIDE.md', 'section' => 'Deployment']]],
]));
[, $out] = $slash('/docs eval');
check('/docs eval measures the search on the project\'s own questions',
    str_contains($out, 'measured on 1 questions') && preg_match('/keywords\s+procedure\s+1\s+100%/u', $out) === 1, $out);

[, $out] = $slash('/tools');
check('/tools lists what the model can call', str_contains($out, 'compter'), $out);

[, $out] = $slash('/skills');
check('/skills is honest about having none', str_contains($out, 'No skills available'), $out);

[, $out] = $slash('/mcp');
check('/mcp says where servers would be declared', str_contains($out, 'mcp.json'), $out);

// ---- permissions -----------------------------------------------------------
[, $out] = $slash('/permissions');
check('/permissions shows none when none were granted', str_contains($out, '— none'), $out);

$permissions->allow('shell_exec');
$session->allowForSession('file_write');
[, $out] = $slash('/permissions');
check('a standing grant is listed as permanent', str_contains($out, 'shell_exec'), $out);
check('and a session one apart from it', str_contains($out, 'file_write') && str_contains($out, 'session only'), $out);

[, $out] = $slash('/permissions revoke shell_exec');
check('/permissions revoke withdraws it', str_contains($out, 'Withdrawn') && !$permissions->isAllowed('shell_exec'), $out);
[, $out] = $slash('/permissions revoke jamais');
check('revoking what was never granted says so', str_contains($out, 'Not a standing grant'), $out);

// ---- what the session is spending ------------------------------------------
[, $out] = $slash('/context');
check('/context names the backend in force', str_contains($out, 'online, api.example.com'), $out);
check('and where the choice came from', str_contains($out, 'machine config'), $out);
check('it reports no measurement before the first turn', str_contains($out, 'not measured yet'), $out);
check('and no residency line, which means nothing on an API', !str_contains($out, 'residency'), $out);

$usage->record(['prompt' => 1200, 'completion' => 300, 'cached' => 400]);
$speed->observe(900.0, 40.0);
[, $out] = $slash('/context');
check('once a request has been made, consumption is shown',
    str_contains($out, 'Session usage') && str_contains($out, '1,200'), $out);
check('with the provider\'s cache hits apart', str_contains($out, '400 of them cached'), $out);
check('and no cost claimed without a declared price', str_contains($out, 'no price'), $out);
check('the measured throughput appears too', str_contains($out, '900 tokens/s'), $out);

// ---- compaction and reset --------------------------------------------------
[, $out] = $slash('/compact');
check('/compact on a short conversation has nothing to do', str_contains($out, 'Nothing to compact'), $out);

$context->keep('file_read', str_repeat("ligne\n", 50));
[, $out] = $slash('/reset');
check('/reset empties the conversation', $bag->conversationSize() === 0, (string) $bag->conversationSize());
check('and rebuilds the system prompt rather than losing it',
    $bag->count() === 1 && str_contains($bag->all()[0]['content'] ?? '', 'Sherpa'), json_encode($bag->all()));
check('the excerpts of that conversation go with it', $context->count() === 0, (string) $context->count());
check('and it says so', str_contains($out, 'History cleared'), $out);

// ---- conversations kept, and picked back up --------------------------------
[, $out] = $slash('/sessions');
check('/sessions with nothing saved says so', str_contains($out, 'No conversation saved'), $out);
[, $out] = $slash('/resume');
check('/resume with nothing to resume says so', str_contains($out, 'No other conversation'), $out);

// A first conversation, as the command would leave it after a turn: with an
// elided tool output whose stub quotes an excerpt id.
$excerptId = $context->keep('file_read', "class Invoice\n{\n    public function subtotal() {}\n}");
$bag->user('Why is the subtotal wrong?');
$bag->add(['role' => 'assistant', 'content' => '', 'tool_calls' => [
    ['id' => 'call00007', 'type' => 'function', 'function' => ['name' => 'file_read', 'arguments' => ['path' => 'src/Invoice.php']]],
]]);
$bag->tool('file_read', "[… output elided — context_recall(id: {$excerptId})]", 'call00007');
$bag->assistant('The quantity is left out of the sum.');
check('a conversation is saved after its turn', $commands->saveSession($bag));
$first = $sessions->currentId();

[, $out] = $slash('/reset');
check('/reset says the conversation it leaves can be picked up again', str_contains($out, '/resume'), $out);
check('and starts a new one rather than writing over it', $sessions->currentId() === null);

$bag->user('List the routes');
$bag->assistant('Il y en a quatre.');
$commands->saveSession($bag);

[, $out] = $slash('/sessions');
check('/sessions lists both, the one in progress marked',
    str_contains($out, 'List the routes') && str_contains($out, 'subtotal') && str_contains($out, 'current'), $out);

// Resumed with another model than the one that held it.
$platform->useModel('autre/modele', 32768);
[$handled, $out] = $slash('/resume 2');
check('/resume <n> picks that line of /sessions', $handled && str_contains($out, 'resumed') && str_contains($out, 'subtotal'), $out);
check('the conversation is back, message for message',
    $bag->conversationSize() === 4 && ($bag->all()[3]['tool_call_id'] ?? null) === 'call00007', json_encode($bag->all()));
check('under a freshly built system prompt, which says it is a resumed conversation',
    str_contains($bag->all()[0]['content'] ?? '', 'Sherpa') && str_contains($bag->all()[0]['content'] ?? '', 'Resumed conversation'),
    (string) ($bag->all()[0]['content'] ?? ''));
check('and warns that the files may have changed since',
    str_contains($bag->all()[0]['content'] ?? '', 'read a file again'), (string) ($bag->all()[0]['content'] ?? ''));
check('the excerpt its stub quotes answers under the same id',
    str_contains((string) $context->get($excerptId, 2000), 'subtotal'), (string) $context->get($excerptId, 2000));
check('a model change since is said, not left to be noticed',
    str_contains($out, 'distant/modele') && str_contains($out, 'autre/modele'), $out);
check('and where it stopped is shown', str_contains($out, 'Last answer') && str_contains($out, 'quantity'), $out);
check('what is written next goes into the resumed conversation', $sessions->currentId() === $first, (string) $sessions->currentId());

// Nothing was confirmed because nothing was lost: the one just left is there.
check('the conversation left behind is still listed',
    count(array_filter($sessions->all(), fn($s) => $s->title === 'List the routes')) === 1);

[, $out] = $slash('/resume 1');
check('resuming the conversation already open says so', str_contains($out, 'already the conversation you are in'), $out);
[, $out] = $slash('/resume 99');
check('a number past the list is named, not guessed at', str_contains($out, 'No conversation "99"'), $out);

// Two conversations, and a bare /resume going back and forth between them —
// which only works if resuming counts as activity.
[, $out] = $slash('/resume');
check('a bare /resume goes back to the conversation just left',
    str_contains($out, 'List the routes') && ($bag->all()[1]['content'] ?? '') === 'List the routes', $out);
[, $out] = $slash('/resume');
check('and again, back to the other one', str_contains($out, 'subtotal'), $out);

if ($realHome !== null) {
    $_SERVER['HOME'] = $realHome;
}
exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
