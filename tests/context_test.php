<?php
// Verification of ContextBudget accounting/calibration and HistoryCompactor.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\ContextBudget;
use App\Agent\HistoryCompactor;
use App\Agent\MessageBag;
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
    return new OllamaPlatform(new MockHttpClient(new MockResponse($chunks ?: ['{"message":{"content":"résumé"},"done":true}'])), 'http://x', 'm');
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
check('eliding alone was enough — no summary pass', !in_array('historique résumé', $actions, true), json_encode($actions, JSON_UNESCAPED_UNICODE));
check('compaction shrinks the estimate', $sizeAfter < $sizeBefore, "{$sizeBefore} -> {$sizeAfter}");

$all = $bag->all();
check('the system message survives compaction', ($all[0]['role'] ?? '') === 'system' && str_contains($all[0]['content'], 'SYSTEM PROMPT'));

$recentTool = null;
foreach (array_slice($all, -3) as $m) {
    if (($m['role'] ?? '') === 'tool') {
        $recentTool = $m;
    }
}
check('the most recent tool output is left intact', $recentTool !== null && !str_contains($recentTool['content'] ?? '', 'élidée'));

$elidedFound = false;
foreach ($all as $m) {
    if (($m['role'] ?? '') === 'tool' && str_contains($m['content'] ?? '', 'élidée')) {
        $elidedFound = true;
    }
}
check('an older tool output was elided', $elidedFound);
check('the stub tells the model how to recover it', str_contains(json_encode($all, JSON_UNESCAPED_UNICODE), 'Rappelle l\'outil'));

// ---- eliding is idempotent -------------------------------------------------
$again = $compactor->compact($bag);
check('re-eliding an already-elided stub does nothing', !in_array('1 sortie(s) d\'outil élidée(s)', $again, true), json_encode($again, JSON_UNESCAPED_UNICODE));

// ---- compactor: stage 2, summarisation -------------------------------------
$bag2 = new MessageBag();
$bag2->system('SYSTEM');
for ($i = 0; $i < 12; $i++) {
    $bag2->user("tour {$i} " . str_repeat('bla ', 300));
    $bag2->assistant("réponse {$i}");
}

$tiny = new ContextBudget(contextWindow: 2048, reservedForResponse: 256);
$c2 = new HistoryCompactor(platform(), $tiny);
$actions2 = $c2->compact($bag2);

check('summarisation runs when eliding is not enough', in_array('historique résumé', $actions2, true), json_encode($actions2, JSON_UNESCAPED_UNICODE));

$all2 = $bag2->all();
check('summary replaces the old turns', count($all2) < 25, (string) count($all2));
check('system message still first after summarisation', ($all2[0]['role'] ?? '') === 'system');
check('a summary message was inserted', str_contains(json_encode($all2, JSON_UNESCAPED_UNICODE), 'Résumé des tours précédents'));
check('recent turns are preserved verbatim', str_contains(json_encode($all2, JSON_UNESCAPED_UNICODE), 'réponse 11'));

// ---- a failing summariser must not corrupt history -------------------------
$bag3 = new MessageBag();
$bag3->system('SYSTEM');
for ($i = 0; $i < 12; $i++) {
    $bag3->user("tour {$i} " . str_repeat('bla ', 300));
    $bag3->assistant("réponse {$i}");
}
$countBefore = $bag3->count();

$broken = new OllamaPlatform(new MockHttpClient(new MockResponse('', ['http_code' => 500])), 'http://x', 'm');
$c3 = new HistoryCompactor($broken, new ContextBudget(contextWindow: 2048, reservedForResponse: 256));
$c3->compact($bag3);

check('history is left intact when the summariser fails', $bag3->count() === $countBefore, "{$countBefore} -> {$bag3->count()}");

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
