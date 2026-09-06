<?php
// Verification of ContextBudget accounting/calibration and HistoryCompactor.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\ContextBudget;
use App\Agent\HistoryCompactor;
use App\Agent\InferenceSpeed;
use App\Agent\MessageBag;
use App\Memory\ContextStore;
use App\Platform\OllamaPlatform;
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

// ---- budget arithmetic -----------------------------------------------------
$b = new ContextBudget(contextWindow: 32768, reservedForResponse: 4096, compactAtFraction: 0.75);

check('prompt limit reserves room for the reply', $b->promptLimit() === 32768 - 4096);
check('compaction threshold sits below the limit', $b->compactThreshold() === (int) ((32768 - 4096) * 0.75));
check('empty text costs nothing', $b->estimateText('') === 0);
check('longer text costs more', $b->estimateText(str_repeat('a', 1000)) > $b->estimateText(str_repeat('a', 100)));

$msgs = [['role' => 'user', 'content' => str_repeat('mot ', 500)]];
check('message overhead is counted', $b->estimateMessages($msgs) > $b->estimateText(str_repeat('mot ', 500)));

check('tool_calls add to the estimate', $b->estimateMessages([
    ['role' => 'assistant', 'content' => 'x', 'tool_calls' => [['function' => ['name' => 'file_read', 'arguments' => ['path' => 'a']]]]],
]) > $b->estimateMessages([['role' => 'assistant', 'content' => 'x']]));

check('a small conversation does not trigger compaction', !$b->needsCompaction($msgs));
$huge = [['role' => 'user', 'content' => str_repeat('x', 400_000)]];
check('a huge conversation does trigger compaction', $b->needsCompaction($huge));

// ---- hysteresis ------------------------------------------------------------
// Trigger and target used to be the same number, so every compaction freed just
// enough to fall back under the trigger and the next turn crossed it again.
// Each crossing rewrites history from the front, which costs a full re-read of
// the window on the next request — the largest recurring bill in a long
// session, paid per compaction.
check('the target sits below the trigger', $b->compactTarget() < $b->compactThreshold(),
    "{$b->compactTarget()} vs {$b->compactThreshold()}");
check('and is a real share of the window, not a token below it',
    $b->compactTarget() < (int) ($b->compactThreshold() * 0.8));

$nonsense = new ContextBudget(contextWindow: 32768, compactAtFraction: 0.40, compactToFraction: 0.90);
check('a target above the trigger is clamped, never inverted',
    $nonsense->compactTarget() <= $nonsense->compactThreshold());

// ---- allowances as a share of the window -----------------------------------
// Every fixed cap in the codebase was chosen against a 32k window on a machine
// with no graphics card. They are floors now.
$small = new ContextBudget(contextWindow: 8192);
$large = new ContextBudget(contextWindow: 262144);

check('a small window gets exactly the old fixed floor', $small->shareInChars(0.10, 24000, 200000) === 24000);
check('a large window gets more', $large->shareInChars(0.10, 24000, 200000) > 24000);
check('but never more than the ceiling', $large->shareInChars(0.90, 24000, 200000) === 200000);
check('the floor wins over a share that would undercut it', $large->shareInChars(0.0, 24000, 200000) === 24000);

// ---- InferenceSpeed --------------------------------------------------------
// The speed half of what ContextBudget does for size: learn from what the
// server reports rather than assume a machine.
$speed = new InferenceSpeed();
check('speed starts unmeasured', !$speed->isMeasured());
check('and costs nothing until it is', $speed->secondsToPrefill(10_000) === null);

$speed->observe(1000.0, 50.0);
check('the first sample is taken as-is', $speed->prefillTokensPerSecond() === 1000.0);
check('a re-read is costed in seconds, which is the question callers have',
    (int) round($speed->secondsToPrefill(10_000) ?? 0) === 10);

$speed->observe(2000.0, 50.0);
check('later samples are smoothed, not replaced',
    $speed->prefillTokensPerSecond() > 1000.0 && $speed->prefillTokensPerSecond() < 2000.0,
    (string) $speed->prefillTokensPerSecond());

// A request served from the prefix cache evaluates no prompt: true, and no
// information about the machine.
$before = $speed->prefillTokensPerSecond();
$speed->observe(0.0, 45.0);
check('a zero-duration phase is discarded rather than folded in',
    $speed->prefillTokensPerSecond() === $before, (string) $speed->prefillTokensPerSecond());

$speed->useModel('autre-modele:7b');
check('changing model forgets figures that described the previous weights', !$speed->isMeasured());
$speed->observe(10.0, 2.0);
$speed->useModel('autre-modele:7b');
check('but re-binding the same model keeps them', $speed->isMeasured());

// ---- calibration against server-reported counts ----------------------------
$b2 = new ContextBudget();
$before = $b2->charsPerToken();
check('starts uncalibrated', !$b2->isCalibrated());

// Pretend the server tokenised 10000 chars of JSON into 5000 tokens (2.0 c/t).
$payload = [['role' => 'user', 'content' => str_repeat('x', 9900)]];
$actualChars = mb_strlen(json_encode($payload));
$b2->calibrate((int) round($actualChars / 2.0), $payload);

check('calibration is recorded', $b2->isCalibrated());
check('ratio moved toward the observation', $b2->charsPerToken() < $before, (string) $b2->charsPerToken());
check('server measurement is exposed', $b2->lastMeasured() !== null);

$b3 = new ContextBudget();
$ratioBefore = $b3->charsPerToken();
$b3->calibrate(0, $payload);
check('a zero token count is ignored', $b3->charsPerToken() === $ratioBefore && !$b3->isCalibrated());

$b4 = new ContextBudget();
$ratioBefore = $b4->charsPerToken();
$b4->calibrate(1, $payload); // absurd: ~10k chars/token
check('an implausible ratio is rejected', $b4->charsPerToken() === $ratioBefore);

// ---- compactor: stage 1, eliding tool output -------------------------------
function platform(array $chunks = []): OllamaPlatform
{
    return new OllamaPlatform(new MockHttpClient(new MockResponse($chunks ?: ['{"message":{"content":"summary"},"done":true}'])), 'http://x', 'm');
}

$bag = new MessageBag();
$bag->system('SYSTEM PROMPT');
for ($i = 0; $i < 8; $i++) {
    $bag->user("question {$i}");
    $bag->tool('file_read', str_repeat("ligne de contenu\n", 200)); // ~3.4k chars each
}

// Window chosen so that eliding alone brings us back under the threshold —
// stage 2 must NOT run here, or there is nothing left to assert about stubs.
$budget = new ContextBudget(contextWindow: 8192, reservedForResponse: 512);
$compactor = new HistoryCompactor(platform(), $budget);

$sizeBefore = $budget->estimateMessages($bag->all());
$actions = $compactor->compact($bag);
$sizeAfter = $budget->estimateMessages($bag->all());

check('compaction reports what it did', $actions !== [], json_encode($actions, JSON_UNESCAPED_UNICODE));
check('eliding alone was enough — no summary pass', !in_array('history summarised', $actions, true), json_encode($actions, JSON_UNESCAPED_UNICODE));
check('compaction shrinks the estimate', $sizeAfter < $sizeBefore, "{$sizeBefore} -> {$sizeAfter}");

$all = $bag->all();
check('the system message survives compaction', ($all[0]['role'] ?? '') === 'system' && str_contains($all[0]['content'], 'SYSTEM PROMPT'));

$recentTool = null;
foreach (array_slice($all, -3) as $m) {
    if (($m['role'] ?? '') === 'tool') {
        $recentTool = $m;
    }
}
check('the most recent tool output is left intact', $recentTool !== null && !str_contains($recentTool['content'] ?? '', 'elided'));

$elidedFound = false;
foreach ($all as $m) {
    if (($m['role'] ?? '') === 'tool' && str_contains($m['content'] ?? '', 'elided')) {
        $elidedFound = true;
    }
}
check('an older tool output was elided', $elidedFound);
check('the stub tells the model how to recover it', str_contains(json_encode($all, JSON_UNESCAPED_UNICODE), 'Call the tool again'));

// ---- eliding is idempotent -------------------------------------------------
$again = $compactor->compact($bag);
check('re-eliding an already-elided stub does nothing', !in_array('1 tool output(s) elided', $again, true), json_encode($again, JSON_UNESCAPED_UNICODE));

// ---- compactor: stage 2, summarisation -------------------------------------
$bag2 = new MessageBag();
$bag2->system('SYSTEM');
for ($i = 0; $i < 12; $i++) {
    $bag2->user("turn {$i} " . str_repeat('bla ', 300));
    $bag2->assistant("reply {$i}");
}

$tiny = new ContextBudget(contextWindow: 2048, reservedForResponse: 256);
$c2 = new HistoryCompactor(platform(), $tiny);
$actions2 = $c2->compact($bag2);

check('summarisation runs when eliding is not enough', in_array('history summarised', $actions2, true), json_encode($actions2, JSON_UNESCAPED_UNICODE));

$all2 = $bag2->all();
check('summary replaces the old turns', count($all2) < 25, (string) count($all2));
check('system message still first after summarisation', ($all2[0]['role'] ?? '') === 'system');
check('a summary message was inserted', str_contains(json_encode($all2, JSON_UNESCAPED_UNICODE), 'Summary of the earlier turns'));
check('recent turns are preserved verbatim', str_contains(json_encode($all2, JSON_UNESCAPED_UNICODE), 'reply 11'));

// ---- a summary never separates a call from its results ---------------------
// The cut counts back a fixed number of messages, and when that lands among
// tool results, the kept history opens on results whose call was summarised
// away. Ollama accepted that; a hosted API rejects it on every request after.
$pairBag = new MessageBag();
$pairBag->system('SYSTEM');
$pairBag->user('turn 0 ' . str_repeat('bla ', 400));
$pairBag->assistant('reply 0');
$pairBag->user('turn 1 ' . str_repeat('bla ', 400));
$pairBag->add(['role' => 'assistant', 'content' => '', 'tool_calls' => [
    ['id' => 'aaaaaaaaa', 'function' => ['name' => 'file_read', 'arguments' => ['path' => 'a.php']]],
    ['id' => 'bbbbbbbbb', 'function' => ['name' => 'file_read', 'arguments' => ['path' => 'b.php']]],
    ['id' => 'ccccccccc', 'function' => ['name' => 'file_read', 'arguments' => ['path' => 'c.php']]],
]]);
// Six messages from the end is the first of these three results.
$pairBag->tool('file_read', 'contenu a', 'aaaaaaaaa');
$pairBag->tool('file_read', 'contenu b', 'bbbbbbbbb');
$pairBag->tool('file_read', 'contenu c', 'ccccccccc');
$pairBag->assistant('lu');
$pairBag->user('turn 2 ' . str_repeat('bla ', 400));
$pairBag->assistant('fin');

$pairActions = (new HistoryCompactor(platform(), new ContextBudget(contextWindow: 2048, reservedForResponse: 256)))->compact($pairBag);
check('the summary ran on a history cut among tool results', in_array('history summarised', $pairActions, true),
    json_encode($pairActions, JSON_UNESCAPED_UNICODE));

$callIds = [];
$orphans = 0;
foreach ($pairBag->all() as $m) {
    foreach ($m['tool_calls'] ?? [] as $c) {
        $callIds[] = $c['id'];
    }
    if (($m['role'] ?? '') === 'tool' && !in_array($m['tool_call_id'] ?? null, $callIds, true)) {
        $orphans++;
    }
}
check('no result is kept without the call it answers', $orphans === 0, json_encode($pairBag->all(), JSON_UNESCAPED_UNICODE));
check('the call was kept with its results rather than summarised away', count($callIds) === 3, json_encode($callIds));
check('and the older turns were still folded', !str_contains(json_encode($pairBag->all(), JSON_UNESCAPED_UNICODE), 'turn 0'));

// ---- one long exchange: the question stays, word for word -----------------
// A question, then search after search, fills the window on its own, and the
// cut falls inside that exchange. Folded into the summary, the question left
// the model a paraphrase to work from — and the session no user message to be
// saved under: "Impossible d'enregistrer la conversation".
$longBag = new MessageBag();
$longBag->system('SYSTEM');
$longBag->user('Which version added the current date on estimates?');
for ($i = 0; $i < 6; $i++) {
    $id = sprintf('call%05d', $i);
    $longBag->add(['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['id' => $id, 'function' => ['name' => 'doc_search', 'arguments' => ['query' => "search {$i}"]]],
    ]]);
    $longBag->tool('doc_search', str_repeat("passage {$i} ", 300), $id);
}

$longActions = (new HistoryCompactor(platform(), new ContextBudget(contextWindow: 2048, reservedForResponse: 256)))->compact($longBag);
$long = $longBag->all();
$shape = json_encode(array_map(fn($m) => $m['role'] . ': ' . mb_substr((string) ($m['content'] ?? ''), 0, 25), $long), JSON_UNESCAPED_UNICODE);

check('one long exchange is summarised like several', in_array('history summarised', $longActions, true), json_encode($longActions, JSON_UNESCAPED_UNICODE));
check('its question comes right after the summary, word for word',
    str_starts_with((string) ($long[1]['content'] ?? ''), '[Summary')
    && ($long[2]['role'] ?? '') === 'user'
    && ($long[2]['content'] ?? '') === 'Which version added the current date on estimates?', $shape);
check('once', substr_count(json_encode($long, JSON_UNESCAPED_UNICODE), 'current date on estimates') === 1, $shape);
check('and followed by a call, not by a result without one', ($long[3]['role'] ?? '') === 'assistant' && !empty($long[3]['tool_calls']), $shape);

// ---- a failing summariser must not corrupt history -------------------------
$bag3 = new MessageBag();
$bag3->system('SYSTEM');
for ($i = 0; $i < 12; $i++) {
    $bag3->user("turn {$i} " . str_repeat('bla ', 300));
    $bag3->assistant("reply {$i}");
}
$countBefore = $bag3->count();

$broken = new OllamaPlatform(new MockHttpClient(new MockResponse('', ['http_code' => 500])), 'http://x', 'm');
$c3 = new HistoryCompactor($broken, new ContextBudget(contextWindow: 2048, reservedForResponse: 256));
$c3->compact($bag3);

check('history is left intact when the summariser fails', $bag3->count() === $countBefore, "{$countBefore} -> {$bag3->count()}");

// ---- which stage runs first depends on the machine, not on the method body --
// Eliding is free and destroys the content: the model re-reads the file, which
// is another turn and another full prompt evaluation. Summarising costs a
// forward pass and keeps the conclusions. Which is cheaper is a wall-clock
// question, so it is asked rather than assumed.
function loadedBag(): MessageBag
{
    $bag = new MessageBag();
    $bag->system('SYSTEM PROMPT');
    for ($i = 0; $i < 8; $i++) {
        $bag->user("question {$i}");
        $bag->tool('file_read', str_repeat("ligne de contenu\n", 200));
    }

    return $bag;
}

$window = fn() => new ContextBudget(contextWindow: 8192, reservedForResponse: 512);

// Unmeasured: the frugal order, which is also what a slow machine wants.
$slowBag = loadedBag();
$slowActions = (new HistoryCompactor(platform(), $window()))->compact($slowBag);
check('an unmeasured machine elides and does not pay for a summary',
    !in_array('history summarised', $slowActions, true), json_encode($slowActions, JSON_UNESCAPED_UNICODE));

// Measured and fast: summarise first, and the recent tool output survives.
$fast = new InferenceSpeed();
$fast->observe(100_000.0, 500.0);

$fastBag = loadedBag();
$fastActions = (new HistoryCompactor(platform(), $window(), $fast))->compact($fastBag);

check('a fast machine summarises first', in_array('history summarised', $fastActions, true),
    json_encode($fastActions, JSON_UNESCAPED_UNICODE));
check('and having summarised, elides nothing',
    !str_contains(json_encode($fastActions, JSON_UNESCAPED_UNICODE), 'elided'),
    json_encode($fastActions, JSON_UNESCAPED_UNICODE));
check('so recent tool output is kept verbatim rather than stubbed',
    !str_contains(json_encode($fastBag->all(), JSON_UNESCAPED_UNICODE), 'elided'));

// A measured but slow machine must behave like the unmeasured one: the branch
// is about seconds, not about whether a number exists.
$slow = new InferenceSpeed();
$slow->observe(12.0, 3.0);
$crawlBag = loadedBag();
$crawlActions = (new HistoryCompactor(platform(), $window(), $slow))->compact($crawlBag);
check('a measured but slow machine still takes the frugal branch',
    !in_array('history summarised', $crawlActions, true), json_encode($crawlActions, JSON_UNESCAPED_UNICODE));

// ---- compaction aims at the target, not merely under the trigger -----------
$deepBag = loadedBag();
$deepBudget = $window();
(new HistoryCompactor(platform(), $deepBudget))->compact($deepBag);
check('compaction lands at or below the target, so the next one is far off',
    !$deepBudget->isAboveTarget($deepBag->all()),
    $deepBudget->estimateMessages($deepBag->all()) . ' vs target ' . $deepBudget->compactTarget());

// ---- a cancelled summary must not replace the history ----------------------
// askForSummary used to call chat(), which has no cancellation checkpoint: a
// Ctrl+C during compaction did nothing until the pass finished.
$bag4 = new MessageBag();
$bag4->system('SYSTEM');
for ($i = 0; $i < 12; $i++) {
    $bag4->user("turn {$i} " . str_repeat('bla ', 300));
    $bag4->assistant("reply {$i}");
}
$countBefore = $bag4->count();

$cancelled = new App\Runtime\Interrupt();
$cancelled->request();
$c4 = new HistoryCompactor(platform(), new ContextBudget(contextWindow: 2048, reservedForResponse: 256), null, $cancelled);
$c4->compact($bag4);

check('a cancelled summary leaves the history alone', $bag4->count() === $countBefore,
    "{$countBefore} -> {$bag4->count()}");

// ---- elision stops being a loss --------------------------------------------
// The stub used to be the whole story: the only way back to an elided body was
// to run the tool again, which is another turn and another full prefill.
$kept = new ContextStore();
$kept->open();

// Sized like loadedBag() above, so eliding alone brings us under target and
// the stubs survive to be asserted on — a summary would fold them away, which
// is correct behaviour and simply a different scenario.
$swapBag = new MessageBag();
$swapBag->system('SYSTEM PROMPT');
for ($i = 0; $i < 8; $i++) {
    $swapBag->user("question {$i}");
    $swapBag->tool('file_read', "fichier {$i}\n" . str_repeat("class PaymentGateway {}\n", 140));
}

$swapActions = (new HistoryCompactor(platform(), $window(), null, null, $kept))->compact($swapBag);

check('eliding still happens', str_contains(json_encode($swapActions, JSON_UNESCAPED_UNICODE), 'elided'),
    json_encode($swapActions, JSON_UNESCAPED_UNICODE));
check('but the body was kept rather than dropped', $kept->count() > 0, (string) $kept->count());
check('and it is findable again without re-running the tool',
    str_contains($kept->search('PaymentGateway', 4000), 'PaymentGateway'),
    substr($kept->search('PaymentGateway', 4000), 0, 200));

// A file where every line matches is the whole file, and handing that back
// would undo the compaction. It must be capped — and never silently emptied.
$flood = $kept->search('PaymentGateway', 4000);
check('a match-heavy excerpt is capped rather than returned whole',
    substr_count($flood, "\n") < 200, (string) substr_count($flood, "\n"));
check('and the overflow is counted out loud',
    str_contains($flood, 'more matching'), substr($flood, -200));

$transcript = json_encode($swapBag->all(), JSON_UNESCAPED_UNICODE);
check('the stub tells the model how to get it back', str_contains($transcript, 'context_recall'), substr($transcript, 0, 400));
check('and quotes the id to ask for', (bool) preg_match('/context_recall\(id: \d+\)/', $transcript), substr($transcript, 0, 400));

// Without a store, the old behaviour is unchanged — that is what every earlier
// assertion in this file is written against.
$lossyBag = new MessageBag();
$lossyBag->system('SYSTEM PROMPT');
for ($i = 0; $i < 8; $i++) {
    $lossyBag->user("question {$i}");
    $lossyBag->tool('file_read', str_repeat("ligne de contenu\n", 200));
}
(new HistoryCompactor(platform(), $window()))->compact($lossyBag);
check('with no store, the stub still says to re-run the tool',
    str_contains(json_encode($lossyBag->all(), JSON_UNESCAPED_UNICODE), 'Call the tool again'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
