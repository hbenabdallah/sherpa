<?php

declare(strict_types=1);

namespace App\Tests\SessionSearchTest;

use App\Session\SessionHit;

// Searching the project's earlier conversations: what was decided, tried or
// dropped, which the lasting facts do not keep. Locks what is searched (the
// words said, not tool output, not the conversation in progress), how words
// match (accents, endings, short words whole), and that the tool's answer
// stays within its budget and says it is a lead, not a fact.

require __DIR__ . '/../vendor/autoload.php';

use App\Acp\ToolDescriptions;
use App\Project\DockerConfig;
use App\Project\ProjectStore;
use App\Project\StackDetector;
use App\Session\SessionSearch;
use App\Session\SessionStore;
use App\Tool\SessionSearchTool;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr($detail, 0, 600), "\n";
    }
}

// A throwaway HOME: sessions live under ~/.config/sherpa.
$root = sys_get_temp_dir() . '/sherpa-session-search-' . bin2hex(random_bytes(4));
mkdir($root . '/home/.config/sherpa', 0777, true);
mkdir($root . '/projet', 0777, true);
$_SERVER['HOME'] = $root . '/home';

$project = new ProjectStore(new StackDetector())->create('Projet', $root . '/projet', new DockerConfig(enabled: false));
$store = new SessionStore();
$store->bind($project);
$search = new SessionSearch($store);
$tool = new SessionSearchTool($search);

$system = ['role' => 'system', 'content' => 'SYSTEM'];

// ---- nothing saved yet -----------------------------------------------------
check('with no earlier conversation, the search says so rather than "no match"', $search->search('cache') === null);
check('and so does the tool', str_contains($tool('cache'), 'No earlier conversation is saved'));

// ---- two earlier conversations, then the one in progress -------------------
$store->save([
    $system,
    ['role' => 'user', 'content' => 'Should we keep Redis for the session cache?'],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'file_read', 'arguments' => '{}']]]],
    ['role' => 'tool', 'content' => 'framework: { session: { handler_id: redis, zanzibar: true } }', 'tool_call_id' => 'c1'],
    ['role' => 'assistant', 'content' => "We dropped Redis: the sessions go to PostgreSQL now, one service less to run.\nThe handler is PdoSessionHandler."],
    ['role' => 'user', 'content' => 'And the DBAL version?'],
    ['role' => 'assistant', 'content' => 'Doctrine DBAL 4.'],
], [], 'api', 'm');

$store->startNew();
$store->save([
    $system,
    ['role' => 'user', 'content' => 'Pourquoi avons-nous décidé de migrer les factures ?'],
    ['role' => 'assistant', 'content' => 'La migration des factures vers le nouveau schéma évite les doublons de numérotation.'],
], [], 'api', 'm');

$store->startNew();
$store->save([
    $system,
    ['role' => 'user', 'content' => 'Redis again, in the conversation in progress'],
], [], 'api', 'm');

$redis = $search->search('redis session') ?? [];
check('a question asked before is found', ($redis[0]->session->title ?? '') === 'Should we keep Redis for the session cache?', var_export($redis, true));
check('the conversation in progress is not searched: it is already in the context', array_filter($redis, static fn(SessionHit $hit): bool => $hit->session->id === $store->currentId()) === []);
$question = array_first(array_filter($redis, static fn(SessionHit $hit): bool => $hit->role === 'user')) ?? null;
check('a question comes with the start of the answer it got', str_contains($question->reply ?? '', 'We dropped Redis'), var_export($question, true));

check('tool output is not searched: the files are read fresher by the tools', $search->search('zanzibar') === []);
check('accents do not matter: "decide" finds "décidé"', count($search->search('decide') ?? []) === 1);
$migration = $search->search('migrations') ?? [];
check('a long word matches its other endings: "migrations" finds "migrer", and "migration" in the answer', count($migration) === 1 && str_contains($migration[0]->excerpt, 'migrer') && str_contains($migration[0]->reply ?? '', 'migration'), var_export($migration, true));
check('a short word matches whole: "db" does not find "DBAL"', $search->search('db') === []);
check('a query of stop words alone finds nothing', $search->search('the of what') === []);

// ---- ranking ----------------------------------------------------------------
$both = $search->search('postgresql factures') ?? [];
$found = array_unique(array_map(static fn(SessionHit $hit): string => $hit->session->title, $both));
check('each term finds its own conversation', count($found) === 2, var_export($found, true));

// ---- the tool's answer -------------------------------------------------------
$answer = $tool('redis');
check('the answer says it is a lead to check, not a fact', str_contains($answer, 'not necessarily true now'), $answer);
check('it names the conversation, its date and how to reopen it', (bool) preg_match('/## “Should we keep Redis for the session cache\?” — \d{4}-\d{2}-\d{2} \(\/resume \d{8}-\d{6}-[0-9a-f]{4}\)/', $answer), $answer);
check('it tells who said what: the user, or the model itself', str_contains($answer, '[user] Should we keep Redis') && str_contains($tool('postgresql'), '[you] We dropped Redis'), $answer);
check('a question and its answer that both match: the answer is shown once, under its question', substr_count($answer, 'We dropped Redis') === 1 && str_contains($answer, '[you, in reply] We dropped Redis'), $answer);
check('a search that matches nothing says so', str_contains($tool('kubernetes'), 'No earlier conversation mentions "kubernetes"'));
check('an empty search asks for keywords', str_contains($tool('  '), 'Give keywords'));

// ---- the budget ----------------------------------------------------------------
$store->startNew();
$store->save([
    $system,
    ['role' => 'user', 'content' => str_repeat("A long note about the queue worker and its retries.\n", 400)],
], [], 'api', 'm');
$store->startNew();
$long = $tool('queue worker retries');
check('a long match is cut to its matching lines, within the budget', mb_strlen($long) <= 3000 && str_contains($long, 'queue worker'), (string) mb_strlen($long));

// ---- in an editor ------------------------------------------------------------
check('an editor draws it as a search', ToolDescriptions::kind('session_search') === 'search');
check('with the words searched in its title', ToolDescriptions::title('session_search', ['search' => 'redis']) === 'Search earlier conversations for redis');

exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
