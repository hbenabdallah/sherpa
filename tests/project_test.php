<?php
// Project identity: the slug names the memory directory and is what `sherpa -p`
// takes on the command line, so getting it wrong is not cosmetic.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Project\DockerConfig;
use App\Project\ProjectStore;
use App\Project\StackDetector;

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

// ProjectStore writes under $HOME; keep it away from the real config.
$realHome = $_SERVER['HOME'] ?? null;
$home = sys_get_temp_dir() . '/sherpa-proj-' . bin2hex(random_bytes(4));
mkdir($home . '/.config/sherpa', 0777, true);
$_SERVER['HOME'] = $home;

$work = $home . '/travail';
mkdir($work, 0777, true);

$store = new ProjectStore(new StackDetector());
$none = new DockerConfig(enabled: false);

// ---- accents ---------------------------------------------------------------
// Stripping everything outside [a-z0-9] dropped accented letters outright, so
// "Éditeur" became "diteur" — in a tool whose interface is entirely in French.
$project = $store->create("Éditeur d'essai", $work, $none);
check('an accented name keeps its letters', $project->slug === 'editeur-d-essai', $project->slug);

check('German sharp s is spelled out', $store->create('Straße', $work, $none)->slug === 'strasse');
check('a Cyrillic name is transliterated', $store->create('Проект', $work, $none)->slug === 'proekt');

// ---- names that leave nothing behind ---------------------------------------
// An empty slug is a blank key in projects.yaml and a directory literally
// called "projects//memory.db".
$symbols = $store->create('***', $work, $none);
check('a name with no letters still gets a usable slug', $symbols->slug !== '' && preg_match('/^[a-z0-9-]+$/', $symbols->slug) === 1, $symbols->slug);
check('and it lands in a real memory directory', !str_contains($symbols->memoryDb, '//'), $symbols->memoryDb);

// Deriving it from the name rather than from the clock means retyping the same
// name reaches the same project instead of a new one every time.
$store->delete($symbols->slug);
check('the same unspellable name derives the same slug', $store->create('***', $work, $none)->slug === $symbols->slug);

// ---- collisions ------------------------------------------------------------
$first = $store->create('Duplicata', $work, $none);
$second = $store->create('Duplicata', $work, $none);
check('a repeated name does not overwrite the first project', $first->slug !== $second->slug, "{$first->slug} vs {$second->slug}");
check('both are still listed', count(array_filter($store->all(), fn($p) => $p->name === 'Duplicata')) === 2);

// Accents must not collide two different names into one slug either.
check('accented and unaccented names stay distinct',
    $store->create('Café', $work, $none)->slug !== $store->create('Cafe', $work, $none)->slug);

// ---- deleting takes the memory with it -------------------------------------
// Slugs come from names, so re-adding a project called the same thing lands on
// the same slug — and used to inherit everything Sherpa believed about the one
// that was deleted.
$doomed = $store->create('À supprimer', $work, $none);
$memoryDir = dirname($doomed->memoryDb);   // create() already made this
file_put_contents($doomed->memoryDb, 'des faits');

check('the project is gone from the list', (function () use ($store, $doomed) {
    $store->delete($doomed->slug);

    return $store->get($doomed->slug) === null;
})());
check('and its memory is gone from disk', !is_dir($memoryDir), $memoryDir);

$again = $store->create('À supprimer', $work, $none);
check('re-adding the same name inherits nothing', !is_file($again->memoryDb), $again->memoryDb);

// A memory database the user pointed somewhere else is not ours to remove.
$outside = $home . '/ailleurs';
mkdir($outside, 0777, true);
file_put_contents($outside . '/memory.db', 'ne pas toucher');
$yaml = $home . '/.config/sherpa/projects.yaml';
file_put_contents($yaml, str_replace(
    $again->memoryDb,
    $outside . '/memory.db',
    file_get_contents($yaml),
));

$reloaded = new ProjectStore(new StackDetector());
$reloaded->delete($again->slug);
check('a memory database stored outside the config tree is left alone', is_file($outside . '/memory.db'));

// ---- editing a project -----------------------------------------------------
// Until now the only way to change a project was to hand-edit projects.yaml,
// which is what every problem above came from.
mkdir($work . '/apres', 0777, true);
file_put_contents($work . '/apres/composer.json', '{"require":{"php":"~8.4.0","symfony/framework-bundle":"7.4.*"}}');

$before = $store->create('Avant', $work, $none);
$memoryDb = $before->memoryDb;

$after = $store->update($before, 'Après déménagement', $work . '/apres', new DockerConfig(enabled: true, container: 'app-1', dbContainer: 'db-1'));

check('the name changes', $after->name === 'Après déménagement', $after->name);
check('the path changes', $after->path === $work . '/apres', $after->path);
check('the docker configuration changes', $after->docker->enabled && $after->docker->container === 'app-1', json_encode($after->docker));

// The slug names the memory directory and is what `sherpa -p` takes. Re-slugging
// a rename would strand everything Sherpa has learned and break every alias.
check('the slug does not follow the name', $after->slug === $before->slug, "{$before->slug} → {$after->slug}");
check('and the memory database stays where it was', $after->memoryDb === $memoryDb, $after->memoryDb);

// The stack belongs to the new location, not the old one.
check('the stack is re-detected at the new path', str_contains($after->stack, 'Symfony'), $after->stack);
check('creation date is preserved', $after->createdAt == $before->createdAt);

$reloaded = new ProjectStore(new StackDetector());
check('the change survives a reload', $reloaded->get($before->slug)?->name === 'Après déménagement',
    (string) $reloaded->get($before->slug)?->name);

// Standing permissions belong to the project, not to its address.
$store->allowTool($after, 'shell_exec');
$moved = $store->update($after, 'Encore ailleurs', $work, $none);
check('standing permissions survive an edit', $moved->allowedTools === ['shell_exec'], json_encode($moved->allowedTools));

$store->delete($moved->slug);

// ---- two sessions at once --------------------------------------------------
// Two terminals is the normal way to use a per-project agent. Each store used
// to write back its whole in-memory map, so whichever saved last silently
// deleted what the other had done.
$sessionA = new ProjectStore(new StackDetector());
$sessionB = new ProjectStore(new StackDetector());

$alpha = $sessionA->create('Alpha', $work, $none);
$sessionB2 = new ProjectStore(new StackDetector());   // B starts after alpha exists
$beta = $sessionB2->create('Beta', $work, $none);

// A now writes anything at all.
$sessionA->allowTool($alpha, 'shell_exec');

$reloaded = new ProjectStore(new StackDetector());
check('a write in one session does not delete another session\'s project',
    $reloaded->get($beta->slug) !== null, implode(',', array_map(fn($p) => $p->slug, $reloaded->all())));
check('and its own change is still recorded',
    in_array('shell_exec', $reloaded->get($alpha->slug)?->allowedTools ?? [], true),
    json_encode($reloaded->get($alpha->slug)?->allowedTools));

// The same guarantee for deletion: A deleting alpha must not resurrect or
// remove anything of B's.
$sessionA->delete($alpha->slug);
$reloaded = new ProjectStore(new StackDetector());
check('a deletion removes only what it deleted',
    $reloaded->get($alpha->slug) === null && $reloaded->get($beta->slug) !== null,
    implode(',', array_map(fn($p) => $p->slug, $reloaded->all())));

// Hand-editing the file is currently the only way to change a project, and it
// used to be reverted by the next write of any running session.
$gamma = $sessionB2->create('Gamma', $work, $none);
$yaml = $home . '/.config/sherpa/projects.yaml';
file_put_contents($yaml, str_replace($work, $work . '/deplace', file_get_contents($yaml)));
mkdir($work . '/deplace', 0777, true);

$sessionB2->allowTool($beta, 'file_write');
$afterEdit = new ProjectStore(new StackDetector());
check('a hand edit to an untouched project survives a later write',
    $afterEdit->get($gamma->slug)?->path === $work . '/deplace',
    (string) $afterEdit->get($gamma->slug)?->path);

// ---- noticing that someone else wrote --------------------------------------
$watcher = new ProjectStore(new StackDetector());
check('nothing has changed right after loading', !$watcher->changedOnDisk());

$other = new ProjectStore(new StackDetector());
$other->create('Surprise', $work, $none);
check('a write from elsewhere is noticed', $watcher->changedOnDisk());

// Our own writes are not somebody else's.
$watcher2 = new ProjectStore(new StackDetector());
$watcher2->create('Nous', $work, $none);
check('our own write does not look like an external one', !$watcher2->changedOnDisk());

foreach (['alpha', 'beta', 'gamma', 'surprise', 'nous'] as $slug) {
    (new ProjectStore(new StackDetector()))->delete($slug);
}

// ---- which project a directory belongs to ----------------------------------
// The launcher passes the directory the user was standing in, so that typing
// `sherpa` inside a project opens it instead of showing a menu.
mkdir($work . '/api/src/Domain', 0777, true);
mkdir($work . '/api-legacy', 0777, true);

$api = $store->create('Api', $work . '/api', $none);
$nested = $store->create('Domaine', $work . '/api/src/Domain', $none);
$legacy = $store->create('Api legacy', $work . '/api-legacy', $none);

check('an exact directory finds its project', $store->forDirectory($work . '/api')?->slug === $api->slug);
check('a subdirectory finds the project above it', $store->forDirectory($work . '/api/config')?->slug === $api->slug);

// A project nested inside another opens as itself, not as its parent.
check('the deepest matching project wins',
    $store->forDirectory($work . '/api/src/Domain/Order')?->slug === $nested->slug,
    (string) $store->forDirectory($work . '/api/src/Domain/Order')?->slug);

// "api-legacy" starts with "api", and matching on the raw prefix would open
// the wrong project entirely.
check('a sibling with a shared prefix is not mistaken for it',
    $store->forDirectory($work . '/api-legacy')?->slug === $legacy->slug,
    (string) $store->forDirectory($work . '/api-legacy')?->slug);

check('a trailing slash changes nothing', $store->forDirectory($work . '/api/')?->slug === $api->slug);
check('a directory under no project finds none', $store->forDirectory('/tmp') === null);
check('an empty directory finds none', $store->forDirectory('') === null);

$store->delete($api->slug);
$store->delete($nested->slug);
$store->delete($legacy->slug);

// ---- reload ----------------------------------------------------------------
$reopened = new ProjectStore(new StackDetector());
check('projects survive a reload with their slugs intact', $reopened->get('editeur-d-essai')?->name === "Éditeur d'essai", json_encode(array_map(fn($p) => $p->slug, $reopened->all())));

if ($realHome !== null) {
    $_SERVER['HOME'] = $realHome;
}
exec('rm -rf ' . escapeshellarg($home));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
