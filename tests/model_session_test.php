<?php
// ModelSession: which backend, which model, and what a change to either costs.
// It came out of the command in one piece, and what it does is exactly what
// nothing could reach before: the boot decisions, /model and /backend.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\ContextBudget;
use App\Agent\InferenceSpeed;
use App\Agent\MessageBag;
use App\Agent\SystemPromptBuilder;
use App\Agent\Tool\Toolbox;
use App\Command\ModelSession;
use App\Config\Backend;
use App\Config\MachineConfig;
use App\Config\ModelProfile;
use App\Config\ModelResolver;
use App\Memory\ContextStore;
use App\Memory\MemoryStore;
use App\Platform\OllamaPlatform;
use App\Platform\OpenAiCompatiblePlatform;
use App\Platform\SwitchablePlatform;
use App\Project\DockerConfig;
use App\Project\Project;
use App\Project\StackDetector;
use App\Skills\SkillRegistry;
use App\TUI\ChatPane;
use App\TUI\LineEditor;
use App\TUI\MarkdownRenderer;
use App\TUI\ModelSelector;
use App\TUI\Terminal;
use App\Usage\Price;
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

$root = sys_get_temp_dir() . '/sherpa-session-' . bin2hex(random_bytes(4));
mkdir($root . '/projet', 0777, true);

/**
 * A session wired to scripted HTTP, so /models and /models/{id} answer what a
 * test wants without a backend existing.
 *
 * @param array<int, MockResponse> $apiResponses
 */
function sessionWith(string $root, array $apiResponses, string $configFile = 'config.yaml'): array
{
    $memory = new MemoryStore();
    $memory->open($root . '/memory-' . bin2hex(random_bytes(3)) . '.db');
    $skills = new SkillRegistry();
    $context = new ContextStore();
    $context->open();
    $budget = new ContextBudget(contextWindow: 32768);
    $speed = new InferenceSpeed();
    $usage = new UsageMeter();
    $chat = new ChatPane(new Terminal(), new MarkdownRenderer());
    $editor = new LineEditor();

    $api = new OpenAiCompatiblePlatform(
        new MockHttpClient(function () use (&$apiResponses) {
            return array_shift($apiResponses) ?? new MockResponse('', ['http_code' => 404]);
        }),
        'https://api.example.com/v1',
        'distant/modele',
    );
    $platform = new SwitchablePlatform(
        new OllamaPlatform(new MockHttpClient(new MockResponse('', ['http_code' => 503])), 'http://ollama.test', 'local:7b'),
        $api,
    );
    $platform->switchTo(Backend::Api);

    $config = new MachineConfig();
    $config->setPath($root . '/' . $configFile);
    $models = new ModelResolver($config, 'ollama:7b', 16384, 'api', 'env/api', 24576);

    $session = new ModelSession(
        $platform, $models, new ModelSelector(new Terminal(), $editor, $platform),
        $budget, $speed, $usage, $chat, $editor, new SystemPromptBuilder($memory, $skills, new StackDetector()),
        $context, new Toolbox([]), new SessionStore(),
    );

    return [$session, $platform, $budget, $speed, $usage, $config];
}

/** Everything a call printed. */
function captured(callable $fn): string
{
    ob_start();
    $fn();

    return Terminal::plain((string) ob_get_clean());
}

$project = new Project(
    slug: 'projet', name: 'projet', path: $root . '/projet', memoryDb: $root . '/memory.db',
    docker: new DockerConfig(enabled: false), stack: '',
    createdAt: new DateTimeImmutable(), lastUsedAt: new DateTimeImmutable(),
);

// ---- applying a profile is five things at once, or none -------------------
[$session, $platform, $budget, $speed, $usage, $config] = sessionWith($root, []);
$config->save(new ModelProfile('distant/modele', 65536, '', Backend::Api));
file_put_contents($root . '/config.yaml', file_get_contents($root . '/config.yaml')
    . "prices:\n  'distant/modele': { input: 1.0, output: 4.0 }\n");

captured(fn() => $session->apply(new ModelProfile('distant/modele', 65536, 'machine config', Backend::Api)));
check('the profile becomes the one in force', $session->profile()?->model === 'distant/modele');
check('the platform is pointed at it', $platform->modelName() === 'distant/modele' && $platform->backend() === Backend::Api);
check('the budget is sized for it', $budget->contextWindow() === 65536, (string) $budget->contextWindow());
check('and its price is in force', $usage->price()?->input === 1.0, json_encode($usage->price()));

$usage->record(['prompt' => 1_000_000, 'completion' => 0]);
check('so what it costs is counted from the declaration', $usage->cost() === 1.0, (string) $usage->cost());

// The window travels with the model: a model change must throw the speed and
// the calibration away, since they described other weights.
$speed->observe(800.0, 30.0);
captured(fn() => $session->apply(new ModelProfile('autre/modele', 32768, 'choisi ici', Backend::Api)));
check('changing model forgets the speed measured for the last one', !$speed->isMeasured());
check('and there is no price for one that was never declared', $usage->price() === null);

// ---- where it runs, in the words the user sees ----------------------------
check('an API is named by its host', str_contains($session->whereItRuns(Backend::Api), 'api.example.com'));
check('and a local model as local', str_contains($session->whereItRuns(Backend::Ollama), 'local'));

$announced = captured(fn() => $session->announce(new ModelProfile('distant/modele', 65536, 'machine config', Backend::Api)));
check('announcing an online model says where the code goes',
    str_contains($announced, 'sent to api.example.com'), $announced);
$local = captured(fn() => $session->announce(new ModelProfile('local:7b', 32768, 'machine config', Backend::Ollama)));
check('a local one has nothing to disclose', !str_contains($local, 'sent to'), $local);

// ---- resolving without a terminal -----------------------------------------
// A pipe cannot answer a menu: the configured default is taken, and said.
[$session] = sessionWith($root, [], 'jamais-choisi.yaml');
$out = '';
$resolved = null;
$out = captured(function () use ($session, &$resolved) {
    $resolved = $session->resolve(null, null, Backend::Api, rememberAsDefault: true);
});
check('with nothing chosen, the .env model is used', $resolved?->model === 'env/api', json_encode($resolved));
check('and the user is told which, and where to choose', str_contains($out, 'No model chosen') && str_contains($out, 'jamais-choisi.yaml'), $out);

$explicit = null;
captured(function () use ($session, &$explicit) {
    $explicit = $session->resolve('un/modele', 8192, Backend::Api, rememberAsDefault: true);
});
check('-m and -c win over everything', $explicit?->model === 'un/modele' && $explicit->contextWindow === 8192, json_encode($explicit));

// ---- a model the backend cannot confirm ------------------------------------
$unknown = null;
$out = captured(function () use ($root, &$unknown) {
    // /models/{id} 404, then /models 200 listing something else: unknown.
    [$session] = sessionWith($root, [
        new MockResponse('', ['http_code' => 404]),
        new MockResponse(json_encode(['data' => [['id' => 'autre/modele']]]), ['response_headers' => ['content-type' => 'application/json']]),
    ], 'inconnu.yaml');
    $unknown = $session->verified(new ModelProfile('absent/modele', 32768, 'command line', Backend::Api));
});
check('an unknown model is reported, not refused', $unknown?->model === 'absent/modele', json_encode($unknown));
check('with what to do about it on an API', str_contains($out, 'provider') && !str_contains($out, 'ollama pull'), $out);

// A window past the model's ceiling is not a preference; it is corrected.
$capped = null;
captured(function () use ($root, &$capped) {
    [$session] = sessionWith($root, [
        new MockResponse(json_encode(['id' => 'distant/modele', 'context_length' => 16384]), ['response_headers' => ['content-type' => 'application/json']]),
    ], 'plafond.yaml');
    $capped = $session->verified(new ModelProfile('distant/modele', 131072, 'command line', Backend::Api));
});
check('a window past the model\'s ceiling is brought back to it', $capped?->contextWindow === 16384, json_encode($capped));

// ---- /backend ---------------------------------------------------------------
[$session, $platform] = sessionWith($root, [], 'backend.yaml');
$bag = new MessageBag();
$bag->system('PROMPT');

$out = captured(fn() => $session->handleBackend('madeup', $bag, $project));
check('/backend refuses a name that is neither', str_contains($out, 'No such backend'), $out);
check('and stays where it was', $platform->backend() === Backend::Api);

$out = captured(fn() => $session->handleBackend('api', $bag, $project));
check('/backend api when already there says so', str_contains($out, 'Already on that backend'), $out);

// Ollama answers 503 here: the switch must not leave the session half moved.
$out = captured(fn() => $session->handleBackend('ollama', $bag, $project));
check('a backend that does not answer is explained', str_contains($out, 'Ollama does not answer'), $out);
check('and the session stays on the one that works', $platform->backend() === Backend::Api, $platform->backend()->value);

// ---- the embedding model, found at launch ------------------------------------
// No catalogue (405), two names the provider does not have, then one it does.
[$embedSession, , , , , $embedConfig] = sessionWith($root, [
    new MockResponse('', ['http_code' => 405]),
    new MockResponse('{"errors":[{"message":"No such model"}]}', ['http_code' => 400]),
    new MockResponse('{"errors":[{"message":"No such model"}]}', ['http_code' => 400]),
    new MockResponse('{"data":[{"index":0,"embedding":[0.1,0.2]}]}', ['http_code' => 200]),
], 'embed.yaml');
$out = captured(fn() => $embedSession->detectEmbeddings(Backend::Api));
check('launching says how the documentation will be searched', str_contains($out, 'by meaning, with @cf/baai/bge-m3'), $out);
check('and remembers it for this machine', $embedConfig->embeddingModel(Backend::Api) === '@cf/baai/bge-m3');
$out = captured(fn() => $embedSession->detectEmbeddings(Backend::Api));
check('the next launch asks nothing and says nothing', $out === '', $out);

[$quotaSession, , , , , $quotaConfig] = sessionWith($root, [
    new MockResponse('', ['http_code' => 405]),
    new MockResponse('{"error":{"message":"bad key"}}', ['http_code' => 401]),
], 'quota.yaml');
$out = captured(fn() => $quotaSession->detectEmbeddings(Backend::Api));
check('a provider that cannot answer today leaves keywords, and says it will look again',
    str_contains($out, 'looks again next launch') && !$quotaConfig->hasEmbeddingChoice(Backend::Api), $out);

exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
