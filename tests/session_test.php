<?php
// Conversations kept on disk: what is written, where, when, and what comes
// back. Until these existed, closing the terminal was the end of a session —
// every question asked, every file read, every half-finished change.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Memory\ContextStore;
use App\Project\DockerConfig;
use App\Project\ProjectStore;
use App\Project\StackDetector;
use App\Session\SessionStore;

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

// A throwaway HOME: sessions live under ~/.config/sherpa, and a test must not
// write a single file into the real one.
$realHome = $_SERVER['HOME'] ?? null;
$root = sys_get_temp_dir() . '/sherpa-sessions-' . bin2hex(random_bytes(4));
mkdir($root . '/home/.config/sherpa', 0777, true);
mkdir($root . '/projet', 0777, true);
$_SERVER['HOME'] = $root . '/home';

$projects = new ProjectStore(new StackDetector());
$project = $projects->create('Projet de test', $root . '/projet', new DockerConfig(enabled: false));

$store = new SessionStore();
$store->bind($project);

$system = ['role' => 'system', 'content' => 'SYSTEM PROMPT'];
$files = fn() => glob($store->directory() . '/*.json') ?: [];

// ---- nothing to keep, nothing written --------------------------------------
check('a conversation nobody spoke in is not saved',
    $store->save([$system], [], 'api', 'm') === false && $files() === []);

// ---- the first save --------------------------------------------------------
$conversation = [
    $system,
    ['role' => 'user', 'content' => "Ajoute un index\nsur la colonne email de User"],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['id' => 'call00001', 'type' => 'function', 'function' => ['name' => 'file_read', 'arguments' => []]],
    ]],
    ['role' => 'tool', 'content' => '<?php class User {}', 'name' => 'file_read', 'tool_call_id' => 'call00001'],
    ['role' => 'assistant', 'content' => 'Done: index added.'],
];

check('a conversation is saved', $store->save($conversation, [], 'api', 'fournisseur/modele'));
check('as one file, in the project\'s own directory',
    count($files()) === 1 && str_contains($files()[0], '/projects/' . $project->slug . '/sessions/'),
    implode(', ', $files()));
check('readable by its owner only — it holds the code it read',
    (fileperms($files()[0]) & 0777) === 0600, sprintf('%o', fileperms($files()[0]) & 0777));

$saved = $store->all()[0] ?? null;
check('the system message is left out: it is rebuilt, never lost',
    $saved !== null && !in_array('system', array_column($saved->messages, 'role'), true));
check('everything else comes back as it was, tool calls and their ids included',
    ($saved?->messages[1]['tool_calls'][0]['id'] ?? null) === 'call00001'
    && ($saved?->messages[2]['tool_call_id'] ?? null) === 'call00001',
    json_encode($saved?->messages));
check('its title is the first question, on one line',
    $saved?->title === 'Ajoute un index sur la colonne email de User', (string) $saved?->title);
check('and it records the model it was held with', $saved?->model === 'fournisseur/modele');

// ---- after every turn, the same file ---------------------------------------
$firstId = $store->currentId();
$conversation[] = ['role' => 'user', 'content' => 'Et un test ?'];
sleep(1);
$store->save($conversation, [], 'api', 'fournisseur/modele');

check('the next turn is written into the same conversation, not a new one',
    count($files()) === 1 && $store->currentId() === $firstId);
check('and its last activity moves forward',
    ($store->all()[0]->updatedAt ?? null) > $saved?->updatedAt);

// Compacting the history folds the first question into a summary, and the
// first user message left is a later one. The list keeps the first name.
$store->save([$system, ['role' => 'assistant', 'content' => "[Summary of the earlier turns]\nan index was added"],
    ['role' => 'user', 'content' => 'Et un test ?']], [], 'api', 'fournisseur/modele');
check('a compacted conversation keeps the name it was listed under',
    ($store->all()[0]->title ?? '') === 'Ajoute un index sur la colonne email de User', (string) ($store->all()[0]->title ?? ''));

// ---- a new conversation ----------------------------------------------------
$store->startNew();
check('starting over writes nothing by itself', count($files()) === 1);

$store->save([$system, ['role' => 'user', 'content' => 'Where is VAT calculated?']], [], 'api', 'm');
check('the next save is a second conversation, beside the first', count($files()) === 2);
check('the most recently active comes first',
    ($store->all()[0]->title ?? '') === 'Where is VAT calculated?', json_encode(array_map(fn($s) => $s->title, $store->all())));
check('and "the previous one" is never the one already open',
    $store->latest()?->id === $firstId, (string) $store->latest()?->id);

// ---- what comes back, and what does not -----------------------------------
check('a session loads by its id', $store->load($firstId)?->id === $firstId);
check('an id shaped like a path is refused before it gets near one',
    $store->load('../../projects') === null && $store->load('20260101-000000-abcd/../../x') === null);

file_put_contents($store->directory() . '/20260101-000000-dead.json', '{ truncated');
check('a damaged file is skipped, and costs no other session',
    count($store->all()) === 2, (string) count($store->all()));
unlink($store->directory() . '/20260101-000000-dead.json');

// ---- only the most recent are kept -----------------------------------------
for ($i = 0; $i < SessionStore::KEEP + 3; $i++) {
    $store->startNew();
    $store->save([$system, ['role' => 'user', 'content' => "question {$i}"]], [], 'api', 'm');
}
check('a project keeps its ' . SessionStore::KEEP . ' most recent conversations',
    count($files()) === SessionStore::KEEP, (string) count($files()));
check('and the one being written is never among those removed',
    $store->load((string) $store->currentId()) !== null);

// ---- the excerpts compaction set aside -------------------------------------
$excerpts = new ContextStore();
$excerpts->open();
$a = $excerpts->keep('file_read', "ligne un\nclass Invoice { subtotal }\nligne trois");
$b = $excerpts->keep('project_grep', 'src/Billing/TaxCalculator.php:12: vatFor');
$exported = $excerpts->export();

check('the excerpts are exported with their ids', array_column($exported, 'id') === [$a, $b], json_encode(array_column($exported, 'id')));

$restored = new ContextStore();
$restored->open();
$restored->keep('file_read', 'une autre conversation, qui doit disparaître');
$restored->import($exported);

// The stubs in a resumed history say context_recall(id: N). Renumbered, the
// model would be handed somebody else's excerpt for the one it asked for.
check('restored excerpts keep their ids, so the stubs still point at them',
    str_contains((string) $restored->get($b, 2000), 'vatFor'), (string) $restored->get($b, 2000));
check('and whatever the store held before is gone', $restored->count() === 2, (string) $restored->count());
check('they are searchable again, not merely present',
    str_contains($restored->search('Invoice', 2000), 'subtotal'), $restored->search('Invoice', 2000));

$next = $restored->keep('file_read', 'nouvel extrait');
check('a new excerpt after a resume takes an id no stub already uses', $next > $b, "{$next} vs {$b}");

// ---- deleting the project deletes its conversations ------------------------
// A project re-added under the same name lands on the same slug; it must not
// inherit someone else's history, any more than it inherits their memory.
$directory = $store->directory();
$projects->delete($project->slug);
check('deleting a project removes its conversations with its memory', !is_dir((string) $directory), (string) $directory);

exec('rm -rf ' . escapeshellarg($root));
if ($realHome !== null) {
    $_SERVER['HOME'] = $realHome;
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
