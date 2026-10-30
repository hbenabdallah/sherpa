<?php

use App\Memory\Recorded;
use App\Project\DockerConfig;

// Files written by an earlier Sherpa, read by this one. The fixtures under
// fixtures/compat-v0.1.0 were written by v0.1.0's own code — config.yaml,
// projects.yaml, a memory database and a saved conversation — and an upgrade
// must find every one of them as it was left: the model chosen, the project
// and its grants, the facts, the conversation to resume.

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Backend;
use App\Config\MachineConfig;
use App\Mcp\McpConfig;
use App\Memory\ContextStore;
use App\Memory\MemoryStore;
use App\Project\ProjectStore;
use App\Project\StackDetector;
use App\Session\SessionStore;
use Symfony\Component\Yaml\Yaml;

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

// The fixtures name the HOME they were written in; set them down in a
// throwaway one, under the same layout.
$fixtures = __DIR__ . '/fixtures/compat-v0.1.0';
$realHome = $_SERVER['HOME'] ?? null;
$root = sys_get_temp_dir() . '/sherpa-compat-' . bin2hex(random_bytes(4));
$sherpa = $root . '/.config/sherpa';
mkdir($sherpa . '/projects/projet-ete/sessions', 0777, true);
mkdir($root . '/projet', 0777, true);
file_put_contents($root . '/projet/composer.json', '{"require":{"php":">=8.2"}}');
$_SERVER['HOME'] = $root;

copy($fixtures . '/config.yaml', $sherpa . '/config.yaml');
file_put_contents($sherpa . '/projects.yaml', str_replace('/tmp/compat-home', $root, (string) file_get_contents($fixtures . '/projects.yaml')));
copy($fixtures . '/memory.db', $sherpa . '/projects/projet-ete/memory.db');
foreach (glob($fixtures . '/*.json') ?: [] as $session) {
    copy($session, $sherpa . '/projects/projet-ete/sessions/' . basename($session));
}

// ---- config.yaml -----------------------------------------------------------
$config = new MachineConfig();
$config->setPath($sherpa . '/config.yaml');
check('config.yaml: the backend chosen is still the one in force', $config->backend() === Backend::Api);
check(
    'the online model, with its context window',
    $config->read()?->model === 'qwen/qwen3-coder' && $config->read()?->contextWindow === 65536,
    json_encode([$config->read()?->model, $config->read()?->contextWindow]),
);
check('its address, from the api section of before providers', $config->apiUrl() === 'https://openrouter.ai/api/v1', (string) $config->apiUrl());
check('the embedding model chosen for it', $config->embeddingModel(Backend::Api) === 'text-embedding-3-small', (string) $config->embeddingModel(Backend::Api));
check('and the local model, kept beside it', $config->read(Backend::Ollama)?->model === 'qwen2.5-coder:7b');

// Recording a provider today must not lose what the old file said.
$config->addProvider('groq', 'https://api.groq.com/openai/v1', null);
$after = Yaml::parseFile($sherpa . '/config.yaml');
check(
    'a provider added afterwards keeps the local model',
    ($after['ollama']['model'] ?? null) === 'qwen2.5-coder:7b',
    json_encode($after),
);
check(
    'and the old address becomes a provider of its own, still the one in force, with its model',
    $config->activeProvider() === 'openrouter' && $config->read()?->model === 'qwen/qwen3-coder'
    && $config->embeddingModel(Backend::Api) === 'text-embedding-3-small'
    && array_keys($config->providers()) === ['openrouter', 'groq'],
    json_encode($after),
);

// Before v0.1.0 the file held a bare model: an Ollama one.
file_put_contents($sherpa . '/bare.yaml', "model: 'llama3.1:8b'\ncontext: 16384\n");
$bare = new MachineConfig();
$bare->setPath($sherpa . '/bare.yaml');
check(
    'a config.yaml of a bare model reads as the local backend',
    $bare->backend() === Backend::Ollama && $bare->read()?->model === 'llama3.1:8b' && $bare->read()?->contextWindow === 16384,
);

// ---- projects.yaml ---------------------------------------------------------
$projects = new ProjectStore(new StackDetector());
$project = $projects->forDirectory($root . '/projet');
check('projects.yaml: the project is found from its directory', $project?->slug === 'projet-ete', (string) $project?->slug);
check('under its name', $project?->name === 'Projet Été', (string) $project?->name);
check('with its container', $project?->docker->enabled === true && $project->docker->container === 'app' && $project->docker->dbContainer === 'db');
check('which runs commands as the user, the default since', $project?->docker->user === DockerConfig::USER_HOST, (string) $project?->docker->user);
// Its one grant was shell_exec itself, which let any command run unasked:
// grants name commands now, and that one is not carried over.
check('and not its grant of shell_exec itself', $project?->allowedTools === [], json_encode($project?->allowedTools));

$projects->touch($project);
$again = new ProjectStore(new StackDetector())->forDirectory($root . '/projet');
check('saved again, nothing of it is lost', $again?->allowedTools === [] && $again->docker->container === 'app' && $again->name === 'Projet Été');

// ---- the memory database ---------------------------------------------------
$memory = new MemoryStore();
$memory->open($project->memoryDb);
$facts = [];
foreach ($memory->recall() as $fact) {
    $facts[$fact->key] = $fact->value;
}
check('memory.db: the facts are there', ($facts['tests'] ?? null) === 'make test runs the suites in Docker', json_encode($facts));
check('with the one superseded no longer current', ($facts['db'] ?? null) === 'PostgreSQL 17' && count($memory->history('db')) === 2, json_encode($facts));
check('and searchable', ($memory->recall('postgresql')[0]->key ?? null) === 'db');
check('it still takes new facts', $memory->remember('ci', 'GitHub Actions') instanceof Recorded && count($memory->recall()) === 3);

// ---- a saved conversation --------------------------------------------------
$sessions = new SessionStore();
$sessions->bind($project);
$saved = $sessions->all()[0] ?? null;
check('sessions: the conversation is listed, under its title', $saved?->title === 'Où est calculée la TVA ?', (string) $saved?->title);
check('it loads by its id', $saved !== null && $sessions->load($saved->id)?->id === $saved->id);
check(
    'its messages and tool calls as they were',
    ($saved?->messages[1]['tool_calls'][0]['id'] ?? null) === 'call00001' && count($saved?->messages ?? []) === 4,
);
$excerpts = new ContextStore();
$excerpts->open();
$excerpts->import($saved?->excerpts ?? []);
check('and the excerpt its stub points at comes back on resume', str_contains((string) $excerpts->get(1, 2000), 'class Invoice'), (string) $excerpts->get(1, 2000));

// ---- mcp.json --------------------------------------------------------------
// Both keys other clients write: "mcpServers" (Claude Desktop, Cursor) and
// "servers" (VS Code).
foreach (['mcpServers', 'servers'] as $key) {
    file_put_contents($sherpa . '/mcp.json', json_encode([$key => [
        'files' => ['command' => 'npx', 'args' => ['-y', 'server-filesystem', '/srv'], 'env' => ['TOKEN' => 't']],
        'tracker' => ['url' => 'https://example.test/mcp', 'headers' => ['Authorization' => 'Bearer x']],
    ]]));
    $mcp = new McpConfig();
    $mcp->setPath($sherpa . '/mcp.json');
    $servers = [];
    foreach ($mcp->servers() as $server) {
        $servers[$server->name] = $server;
    }
    check(
        "mcp.json under \"{$key}\": a server started as a process",
        ($servers['files']->command ?? null) === 'npx' && ($servers['files']->args ?? null) === ['-y', 'server-filesystem', '/srv']
        && ($servers['files']->env ?? null) === ['TOKEN' => 't'] && ($servers['files']->enabled ?? false) === true,
    );
    check('and one reached at an address', ($servers['tracker']->url ?? null) === 'https://example.test/mcp'
        && ($servers['tracker']->headers ?? null) === ['Authorization' => 'Bearer x']);
}

exec('rm -rf ' . escapeshellarg($root));
if ($realHome !== null) {
    $_SERVER['HOME'] = $realHome;
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
