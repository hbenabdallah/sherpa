<?php
// Project memory: what gets kept, what gets found again, and what must never be
// lost quietly.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\MessageBag;
use App\Memory\Extraction;
use App\Memory\Fact;
use App\Memory\FactExtractor;
use App\Memory\MemoryStore;
use App\Memory\Recorded;
use App\Platform\OllamaPlatform;
use App\Runtime\Interrupt;
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
        echo "        ", substr($detail, 0, 300), "\n";
    }
}

/** @return array<int, string> */
function values(array $facts): array
{
    return array_map(fn(Fact $f) => $f->value, $facts);
}

$dir = sys_get_temp_dir() . '/sherpa-mem-' . bin2hex(random_bytes(4));
$db = $dir . '/memory.db';

$store = new MemoryStore();
$store->open($db);

// ---- keys chosen by a 7B model collide, and a collision is not a correction --
// This is the whole reason the schema changed. Asked to label two unrelated
// facts, a small model reaches for the same obvious word, and the second one
// used to delete the first with no trace and no message.
$store->remember('database', 'PostgreSQL 16 en production');
$store->remember('database', 'les migrations Doctrine vivent dans migrations/');

$current = $store->recall();
check('a colliding key does not destroy the earlier fact', count($current) === 2, json_encode(values($current), JSON_UNESCAPED_UNICODE));

// ---- re-learning the same thing is not a change ----------------------------
check('re-recording an identical fact reports no change',
    $store->remember('database', 'PostgreSQL 16 en production') === Recorded::Unchanged);
check('and does not duplicate the row', count($store->recall()) === 2);

check('a genuinely new fact reports as created',
    $store->remember('tests', 'phpunit -c phpunit.xml.dist') === Recorded::Created);

// ---- an explicit correction supersedes, it does not vanish -----------------
$result = $store->supersede('tests', 'make test');
check('correcting a fact reports what happened', $result === Recorded::Superseded);

$current = $store->recall();
check('only the correction is current', in_array('make test', values($current), true) && !in_array('phpunit -c phpunit.xml.dist', values($current), true), json_encode(values($current), JSON_UNESCAPED_UNICODE));
check('the superseded value is still on record', count($store->history('tests')) === 2, json_encode(values($store->history('tests')), JSON_UNESCAPED_UNICODE));

// ---- recall has to find things a substring match cannot --------------------
$store->remember('auth', 'L\'authentification utilise des jetons JWT signés en RS256');
$store->remember('deploiement', 'Le déploiement passe par Ansible vers deux serveurs');

// Word order, and words that are not adjacent in the stored text: LIKE '%...%'
// finds neither, which is what made recall unusable in practice.
$hits = $store->recall('JWT authentification');
check('recall matches words in any order', values($hits) !== [] && str_contains($hits[0]->value, 'JWT'), json_encode(values($hits), JSON_UNESCAPED_UNICODE));

$hits = $store->recall('ansible');
check('recall is case-insensitive', count($hits) === 1, json_encode(values($hits), JSON_UNESCAPED_UNICODE));

$hits = $store->recall('déploiement serveurs');
check('recall ranks the best match first', count($hits) >= 1 && str_contains($hits[0]->value, 'Ansible'), json_encode(values($hits), JSON_UNESCAPED_UNICODE));

// A query the model builds from user prose can contain anything at all. FTS5
// treats most punctuation as syntax and raises on a bad expression.
$hits = $store->recall('"unterminated AND (');
check('a syntactically hostile query does not throw', is_array($hits));
check('an empty query is not a search', count($store->recall('   ')) === count($store->recall()));

// ---- superseded facts stay out of the prompt -------------------------------
$digest = $store->digest();
check('the digest omits superseded values', !str_contains($digest, 'phpunit -c'), $digest);
check('the digest keeps current ones', str_contains($digest, 'make test'), $digest);

// ---- forget ----------------------------------------------------------------
check('forgetting a key removes every version of it', $store->forget('tests') === 2);
check('and says so when there was nothing to forget', $store->forget('inexistant') === 0);

// ---- the digest that goes into every system prompt -------------------------
// It is built once, before anyone has asked anything, so it cannot be relevant
// to a question. What it must not do is drop facts without saying so: the model
// cannot search for what it does not know exists.
$big = new MemoryStore();
$big->open(sys_get_temp_dir() . '/sherpa-digest-' . bin2hex(random_bytes(4)) . '/memory.db');
for ($i = 0; $i < 60; $i++) {
    $big->remember("fait_{$i}", "Une convention du projet décrite avec assez de détail pour être utile, numéro {$i}.");
}

$digest = $big->digest(1600);
check('the digest respects its character budget', mb_strlen($digest) <= 1600, (string) mb_strlen($digest));
check('it details the most recent facts in full', str_contains($digest, 'fait_59: Une convention'), $digest);
check('and names the keys it could not fit', str_contains($digest, 'memory_recall') && str_contains($digest, 'fait_30'), $digest);

// Every key must appear somewhere: detailed, named in the overflow line, or
// counted in the "and N others" tail. Nothing may simply vanish.
preg_match('/et (\d+) autres?/', $digest, $m);
$counted = (int) ($m[1] ?? 0);
$named = substr_count($digest, 'fait_');
check('every stored fact is either shown, named or counted', $named + $counted === 60, "named={$named} counted={$counted}");

// A value long enough to be a document is elided, not allowed to eat the budget.
$big->remember('roman', str_repeat('très long ', 200));
check('an oversized value is elided', str_contains($big->digest(1600), '…'), $big->digest(1600));

$empty = new MemoryStore();
$empty->open(sys_get_temp_dir() . '/sherpa-empty-' . bin2hex(random_bytes(4)) . '/memory.db');
check('an empty memory says so rather than showing an empty section',
    str_contains($empty->digest(), 'aucun fait'), $empty->digest());

// ---- the old schema must not strand anyone's facts -------------------------
$legacyDir = sys_get_temp_dir() . '/sherpa-legacy-' . bin2hex(random_bytes(4));
mkdir($legacyDir, 0777, true);
$legacy = $legacyDir . '/memory.db';
$pdo = new PDO('sqlite:' . $legacy);
$pdo->exec('CREATE TABLE facts (id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL UNIQUE, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
$pdo->exec("INSERT INTO facts (key, value, updated_at) VALUES ('stack', 'Symfony 7.4', '2026-01-01T00:00:00+00:00')");
$pdo = null;

$migrated = new MemoryStore();
$migrated->open($legacy);
$facts = $migrated->recall();
check('facts written under the old schema survive the migration', count($facts) === 1 && $facts[0]->value === 'Symfony 7.4', json_encode(values($facts), JSON_UNESCAPED_UNICODE));
check('and the migrated store accepts colliding keys', $migrated->remember('stack', 'API Platform 4') === Recorded::Created && count($migrated->recall()) === 2);

// Re-opening an already-migrated database must be a no-op, not a second rebuild.
$again = new MemoryStore();
$again->open($legacy);
check('re-opening a migrated database keeps its facts', count($again->recall()) === 2);

// ---- an unopened store is a programming error, not a silent no-op ----------
$closed = new MemoryStore();
$threw = false;
try { $closed->recall(); } catch (\LogicException) { $threw = true; }
check('using a store with no database open throws', $threw);

// ---- the end-of-session extractor ------------------------------------------
// It runs unattended, on whatever a 7B model chose to say, at the moment
// someone has already typed /exit. Every one of these used to be silence.

function extractor(MemoryStore $store, string $reply, Interrupt $interrupt = new Interrupt()): FactExtractor
{
    $platform = new OllamaPlatform(
        new MockHttpClient(new MockResponse(json_encode(['message' => ['content' => $reply], 'done' => true]))),
        'http://x',
        'm',
        32768,
        -1.0,
        $interrupt,
    );

    return new FactExtractor($platform, $store, $interrupt);
}

function conversation(int $exchanges = 2): MessageBag
{
    $bag = new MessageBag();
    $bag->system('SYSTEM: le résumé mémoire du projet vit ici');
    for ($i = 0; $i < $exchanges; $i++) {
        $bag->user("question {$i} sur l'architecture");
        $bag->assistant("réponse {$i}");
    }

    return $bag;
}

$fresh = new MemoryStore();
$fresh->open(sys_get_temp_dir() . '/sherpa-ext-' . bin2hex(random_bytes(4)) . '/memory.db');

$short = new MessageBag();
$short->system('SYSTEM');
$short->user('salut');
$result = extractor($fresh, '[]')->extract($short);
check('a session too short to matter is skipped, and says so', $result->skipped !== null && $result->summary() !== '');

// The reply a small model actually gives: prose, a fence, then the array.
$reply = "Voici les faits que j'ai relevés (voir [le code] et [la config]) :\n"
    . "```json\n" . '[{"key":"auth_strategy","value":"JWT via lexik"},{"key":"tests","value":"make test [pas phpunit]"}]' . "\n```\nJ'espère que cela aide.";
$result = extractor($fresh, $reply)->extract(conversation());
check('facts are found inside prose, fences and stray brackets', $result->created === 2, $result->summary());
check('a "]" inside a value does not end the array early',
    count($fresh->recall('phpunit')) === 1, json_encode(values($fresh->recall()), JSON_UNESCAPED_UNICODE));

// Running it twice must not multiply the same facts.
$result = extractor($fresh, $reply)->extract(conversation());
check('a second pass recognises what it already knows', $result->created === 0 && $result->alreadyKnown === 2, $result->summary());

// Garbage has to be reported, not swallowed. This is what made an empty memory
// database indistinguishable from a working one.
$result = extractor($fresh, 'Je ne peux pas répondre à cette demande.')->extract(conversation());
check('a reply with no JSON at all is reported as a failure', $result->error !== null, $result->summary());
check('and the failure is legible', str_contains($result->summary(), 'impossible'), $result->summary());

// Shapes a model gets wrong: missing fields, wrong types, empty strings, and
// a value long enough to be a document rather than a note.
$sloppy = '[{"key":"ok","value":"une valeur correcte"},{"key":"orphelin"},{"value":"sans clé"},'
    . '{"key":"","value":"clé vide"},{"key":"trop_long","value":"' . str_repeat('x', 500) . '"},'
    . '{"key":["un","tableau"],"value":"clé non scalaire"}]';
$result = extractor($fresh, $sloppy)->extract(conversation());
check('malformed facts are rejected rather than stored', $result->created === 1, $result->summary());
check('and the rejections are counted, not hidden', $result->rejected === 5, $result->summary());

// Cancellation: someone pressed Ctrl+C while waiting to get their shell back.
$cancelling = new Interrupt();
$cancelling->request();
$result = extractor($fresh, $reply, $cancelling)->extract(conversation());
check('an interrupted extraction stores nothing', $result->skipped !== null && $result->created === 0, $result->summary());

// The transcript handed over is evidence, not Sherpa's own words: the system
// prompt already contains the memory summary, and feeding it back invites the
// model to re-extract what it has just been told.
$seen = '';
$recorder = new MockHttpClient(function ($method, $url, $options) use (&$seen) {
    $seen = $options['body'] ?? '';

    return new MockResponse(json_encode(['message' => ['content' => '[]'], 'done' => true]));
});
$bag = conversation();
$bag->tool('file_read', 'CONTENU_DE_FICHIER_VOLUMINEUX');
(new FactExtractor(new OllamaPlatform($recorder, 'http://x', 'm', 32768, -1.0, new Interrupt()), $fresh, new Interrupt()))->extract($bag);
check('the system prompt is not fed back into the extractor', !str_contains($seen, 'le résumé mémoire du projet vit ici'), substr($seen, 0, 200));
check('nor are tool results', !str_contains($seen, 'CONTENU_DE_FICHIER_VOLUMINEUX'), substr($seen, 0, 200));
check('but the conversation itself is', str_contains($seen, 'question 0') && str_contains($seen, 'UTILISATEUR'), substr($seen, 0, 300));

exec('rm -rf ' . escapeshellarg($dir) . ' ' . escapeshellarg($legacyDir));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
