<?php
// Retrieval that does not cost a turn: what compaction takes out of the window
// and can hand back, and the situational signals that rank the memory block
// before anyone has asked anything.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\ContextBudget;
use App\Memory\ContextStore;
use App\Agent\SearchTrace;
use App\Memory\MemoryStore;
use App\Project\RecentActivity;
use App\Tool\ContextRecallTool;

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

// ---- ContextStore: elision becomes swap ------------------------------------
$store = new ContextStore();
$store->open();

check('an empty store holds nothing', $store->count() === 0);
check('and searching it finds nothing', $store->search('quoi que ce soit', 4000) === '');

$controller = implode("\n", [
    '<?php',
    'namespace App\Controller;',
    '',
    'class PaymentController',
    '{',
    '    public function checkout(Request $request): Response',
    '    {',
    '        $intent = $this->stripe->createIntent($request->get("amount"));',
    '',
    '        return $this->json(["client_secret" => $intent->clientSecret]);',
    '    }',
    '',
    '    public function webhook(Request $request): Response',
    '    {',
    '        return new Response("", 204);',
    '    }',
    '}',
]);

$id = $store->keep('file_read', $controller);
check('an elided output is kept', $store->count() === 1);
check('and it hands back an id the stub can quote', $id > 0, (string) $id);

// The whole point: the content comes back without re-running the tool.
$found = $store->search('createIntent', 4000);
check('a keyword finds the passage again', str_contains($found, 'createIntent'), $found);
check('the excerpt carries line numbers', (bool) preg_match('/^\s+8 \|/m', $found), $found);
check('and names which output it came from', str_contains($found, "#{$id}") && str_contains($found, 'file_read'), $found);

// Context lines either side, not the bare hit.
check('surrounding lines come with it', str_contains($found, 'public function checkout'), $found);

// Not the whole file: that would undo the compaction that put it here.
check('but not the entire excerpt', !str_contains($found, 'namespace App\Controller'), $found);

// Two distant hits must not be silently spliced into one run.
$gapped = $store->search('class webhook', 4000);
check('a gap between two runs is marked', str_contains($gapped, '…'), $gapped);

check('a miss says so rather than guessing', $store->search('kubernetes', 4000) === '');

// Retrieval by id, for the stub that quoted one.
$whole = $store->get($id, 4000);
check('an id returns the output in full', $whole !== null && str_contains($whole, 'namespace App\Controller'), (string) $whole);
check('an unknown id returns nothing', $store->get(9999, 4000) === null);

// The cap is not ceremony: handing back everything undoes the compaction.
$store->keep('shell_exec', str_repeat("ligne de sortie tres longue\n", 2000));
$capped = $store->get(2, 500);
check('a large output is truncated to the budget', $capped !== null && mb_strlen($capped) < 700, (string) mb_strlen((string) $capped));
check('and says that it was', str_contains((string) $capped, 'truncated'), (string) $capped);

$store->clear();
check('clearing empties it', $store->count() === 0);

// ---- the tool ---------------------------------------------------------------
$fresh = new ContextStore();
$fresh->open();
$tool = new ContextRecallTool($fresh, new ContextBudget(contextWindow: 32768));

check('with nothing elided, the tool says so plainly',
    str_contains($tool('anything at all'), 'Nothing has been taken out'), $tool('x'));

$fresh->keep('project_grep', "src/Payment/Checkout.php:42:    private StripeClient \$stripe;\n");
check('a keyword search reaches the store', str_contains($tool('stripe'), 'StripeClient'), $tool('stripe'));
check('a search with no match is reported, not thrown',
    str_contains($tool('mongodb'), 'No passage matches'), $tool('mongodb'));
check('an empty call asks for something to go on',
    str_contains($tool(), 'keywords'), $tool());
check('a bad id falls back to suggesting a search',
    str_contains($tool(null, 9999), 'context_recall'), $tool(null, 9999));

// ---- RecentActivity: the situation as a query ------------------------------
$activity = new RecentActivity();

$terms = $activity->termsFrom([
    'src/Payment/StripeGateway.php',
    'src/Payment/Intent.php',
    'tests/payment_test.php',
], '/home/x/projet');

check('a touched directory becomes a term', in_array('payment', $terms, true), implode(',', $terms));
check('so does a class name', in_array('stripegateway', $terms, true), implode(',', $terms));
check('CamelCase is split so the domain word survives', in_array('stripe', $terms, true), implode(',', $terms));
check('the extension is dropped', !in_array('php', $terms, true), implode(',', $terms));

// Words every project shares would boost everything, which boosts nothing.
check('generic path segments are dropped', !in_array('src', $terms, true) && !in_array('tests', $terms, true), implode(',', $terms));

// The launch directory is the cheapest signal there is, and it leads.
$terms = $activity->termsFrom(['src/Mailer/Transport.php'], '/home/x/projet', '/home/x/projet/src/Invoicing');
check('the launch directory is a term', in_array('invoicing', $terms, true), implode(',', $terms));
check('and it outranks what git reported', array_search('invoicing', $terms, true) < array_search('mailer', $terms, true), implode(',', $terms));

check('a launch outside the project contributes nothing',
    !in_array('ailleurs', $activity->termsFrom([], '/home/x/projet', '/home/x/ailleurs'), true));
check('launching at the project root contributes nothing',
    $activity->termsFrom([], '/home/x/projet', '/home/x/projet') === []);

// A project with no repository must cost the ranking its boost and nothing else.
$bare = sys_get_temp_dir() . '/sherpa-norepo-' . bin2hex(random_bytes(4));
mkdir($bare, 0777, true);
check('a project without git yields no terms rather than failing', $activity->terms($bare) === []);
exec('rm -rf ' . escapeshellarg($bare));

// ---- the code half finally gets an instrument ------------------------------
// project_grep answered "No matches found" and nobody counted it, so "should
// Sherpa index the code" could only ever be settled by opinion.
$statDir = sys_get_temp_dir() . '/sherpa-trace-' . bin2hex(random_bytes(4));
$mem = new MemoryStore();
$mem->open($statDir . '/memory.db');

check('a project starts with nothing counted', $mem->searchStats() === ['hits' => 0, 'misses' => 0, 'direct' => 0, 'groping' => 0],
    json_encode($mem->searchStats()));

$trace = new SearchTrace($mem);

// One search then a read: the model knew where it was going.
$trace->beginTurn();
$trace->observe('project_grep');
$trace->observe('file_read');
$trace->endTurn();
check('one search before a read counts as direct', $mem->searchStats()['direct'] === 1, json_encode($mem->searchStats()));

// Three patterns before a read: it was guessing, and that is the failure a
// semantic index would fix — the one a miss count alone cannot see.
$trace->beginTurn();
$trace->observe('project_grep');
$trace->observe('project_grep');
$trace->observe('project_grep');
$trace->observe('file_read');
$trace->endTurn();
check('three searches before a read counts as groping', $mem->searchStats()['groping'] === 1, json_encode($mem->searchStats()));

// What happens after the read is acting on a find, not looking for one.
$trace->beginTurn();
$trace->observe('project_grep');
$trace->observe('file_read');
$trace->observe('project_grep');
$trace->observe('project_grep');
$trace->observe('project_grep');
$trace->endTurn();
check('searches after the first read do not count', $mem->searchStats()['groping'] === 1, json_encode($mem->searchStats()));
check('and that turn was still recorded as direct', $mem->searchStats()['direct'] === 2, json_encode($mem->searchStats()));

// A turn that searched and never read: gave up, or answered from the results.
// Either way the number of attempts is the fact worth keeping.
$trace->beginTurn();
$trace->observe('project_grep');
$trace->observe('project_grep');
$trace->observe('project_grep');
$trace->endTurn();
check('a turn that searches and never reads still settles', $mem->searchStats()['groping'] === 2, json_encode($mem->searchStats()));

// A turn with no searching at all must not be counted as anything.
$before = $mem->searchStats();
$trace->beginTurn();
$trace->observe('memory_recall');
$trace->observe('file_write');
$trace->endTurn();
check('a turn that never searched is not recorded', $mem->searchStats() === $before, json_encode($mem->searchStats()));

// endTurn() runs from a finally and can land twice; it must not double-count.
$before = $mem->searchStats();
$trace->endTurn();
check('closing a settled turn again changes nothing', $mem->searchStats() === $before, json_encode($mem->searchStats()));

// Without a store it is inert rather than fatal — the loop builds one anyway.
$loose = new SearchTrace();
$loose->beginTurn();
$loose->observe('project_grep');
$loose->endTurn();
check('a trace with nowhere to write is harmless', $loose->searchesThisTurn() === 1);

// ---- counters survive the v4 rename ----------------------------------------
// memory_stats stopped being about memory the moment code searches joined it.
$v3Dir = sys_get_temp_dir() . '/sherpa-v3-' . bin2hex(random_bytes(4));
mkdir($v3Dir, 0777, true);
$v3 = $v3Dir . '/memory.db';
$pdo = new PDO('sqlite:' . $v3);
$pdo->exec('CREATE TABLE facts (id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL, value TEXT NOT NULL,
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL, superseded_at TEXT NULL,
            recalled_count INTEGER NOT NULL DEFAULT 0, last_recalled_at TEXT NULL, UNIQUE(key, value))');
$pdo->exec('CREATE TABLE memory_stats (key TEXT PRIMARY KEY, value INTEGER NOT NULL DEFAULT 0)');
$pdo->exec("INSERT INTO memory_stats (key, value) VALUES ('recall_misses', 7)");
$pdo->exec('PRAGMA user_version = 3');
$pdo = null;

$upgraded = new MemoryStore();
$upgraded->open($v3);
check('counters recorded under the old table name survive', $upgraded->recallStats()['misses'] === 7,
    json_encode($upgraded->recallStats()));
$upgraded->bump('grep_misses');
check('and the renamed table takes the new ones', $upgraded->searchStats()['misses'] === 1,
    json_encode($upgraded->searchStats()));

$reopened = new MemoryStore();
$reopened->open($v3);
check('re-opening a v4 database keeps them', $reopened->recallStats()['misses'] === 7);

// A counter is never worth an exception in the middle of a tool call.
$closed = new MemoryStore();
$closed->bump('grep_misses');
check('bumping a store with no database open is silent', $closed->counters() === []);

exec('rm -rf ' . escapeshellarg($statDir) . ' ' . escapeshellarg($v3Dir));

// ---- project_grep reads the regex models actually write -------------------
// In grep's default dialect `createUser|registerUser` searches for a literal
// pipe: nothing found, the model concludes the code is not there, and the
// miss counter records a vocabulary gap that was a syntax one.
$grepDir = sys_get_temp_dir() . '/sherpa-grep-' . bin2hex(random_bytes(4));
mkdir($grepDir . '/src', 0777, true);
file_put_contents($grepDir . '/src/User.php',
    "<?php\nclass User\n{\n    public function createUser() {}\n    public function registerUser() {}\n}\n");
$grepPaths = new App\Project\ProjectPathResolver();
$grepPaths->setRoot($grepDir);
$grepStore = new MemoryStore();
$grepStore->open($statDir . '-grep/memory.db');
$grep = new App\Tool\ProjectGrepTool($grepPaths, null, $grepStore);

$both = $grep('createUser|registerUser');
check('an alternation finds both sides', str_contains($both, 'createUser') && str_contains($both, 'registerUser'), $both);
check('so does a group with a quantifier', str_contains($grep('(create|register)User+'), 'src/User.php'));

// Exit status 2 is grep failing, not grep finding nothing.
$broken = $grep('create(User');
check('a malformed pattern is reported as one', str_starts_with($broken, 'Invalid search pattern'), $broken);
check('and is not counted as a miss', $grepStore->searchStats()['misses'] === 0, json_encode($grepStore->searchStats()));
$grep('nulle_part_dans_le_code');
check('while a real miss still is', $grepStore->searchStats()['misses'] === 1, json_encode($grepStore->searchStats()));

// Seen in the benchmark: `ext: env` looked for *.env and missed .env.example.
file_put_contents($grepDir . '/.env.example', "DATABASE_URL=postgresql://db/boutique\n");
file_put_contents($grepDir . '/phpunit.xml.dist', "<phpunit bootstrap=\"autoload.php\"/>\n");
check('an extension filter finds the template of a config file',
    str_contains($grep('DATABASE_URL', null, 'env'), '.env.example'));
check('and a .dist file with the extension before it', str_contains($grep('bootstrap', null, 'xml'), 'phpunit.xml.dist'));
check('while still filtering out other files', str_starts_with($grep('createUser', null, 'env'), 'No matches'));

$dashed = $grep('-f /etc/passwd');
check('a pattern starting with a dash is searched for, not obeyed as an option',
    str_starts_with($dashed, 'No matches found'), $dashed);

// ---- a read that failed has not found anything ----------------------------
// file_read used to return its errors as ordinary text, so SearchTrace could
// not tell a path that does not exist from one that did: a groping turn was
// recorded as direct the moment the model guessed a file name.
$missing = (new App\Agent\Tool\Toolbox([new App\Tool\FileReadTool($grepPaths)]))
    ->execute(new App\Agent\Tool\ToolCall('c1', 'file_read', ['path' => 'src/Absent.php']));
check('a missing file comes back flagged as an error', $missing->isError, $missing->content);
check('with the same text as before', $missing->content === 'Error: file not found: src/Absent.php', $missing->content);

// A guessed name next to the real one: `.env` beside `.env.example`, or a bare
// file name for one in a subdirectory.
file_put_contents($grepDir . '/.env.example', "APP_ENV=prod\n");
$reader = new App\Agent\Tool\Toolbox([new App\Tool\FileReadTool($grepPaths)]);
$dotenv = $reader->execute(new App\Agent\Tool\ToolCall('c3', 'file_read', ['path' => '.env']));
check('a missing .env points to the .env.example that exists',
    $dotenv->isError && str_contains($dotenv->content, 'did you mean .env.example?'), $dotenv->content);
$bare = $reader->execute(new App\Agent\Tool\ToolCall('c4', 'file_read', ['path' => 'User.php']));
check('a bare file name points to its directory', str_contains($bare->content, 'did you mean src/User.php?'), $bare->content);

// Seen live: a model asked to read the project root. file() on a directory
// printed a PHP notice into the terminal and came back empty, as a success.
$directory = (new App\Agent\Tool\Toolbox([new App\Tool\FileReadTool($grepPaths)]))
    ->execute(new App\Agent\Tool\ToolCall('c2', 'file_read', ['path' => 'src']));
check('reading a directory is an error, not an empty file',
    $directory->isError && str_contains($directory->content, 'is a directory'), $directory->content);

$before = $grepStore->searchStats()['groping'];
$failedRead = new SearchTrace($grepStore);
$failedRead->beginTurn();
$failedRead->observe('project_grep');
$failedRead->observe('file_read', failed: true);
$failedRead->observe('project_grep');
$failedRead->observe('project_grep');
$failedRead->observe('file_read');
$failedRead->endTurn();
check('a failed read is one more guess, not the end of the search',
    $grepStore->searchStats()['groping'] === $before + 1, json_encode($grepStore->searchStats()));

exec('rm -rf ' . escapeshellarg($grepDir) . ' ' . escapeshellarg($statDir . '-grep'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
