<?php
// Verification of model selection: the precedence chain, what gets written down,
// and the two things that must happen when the model changes under a session.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\ContextBudget;
use App\Agent\MessageBag;
use App\Agent\SystemPromptBuilder;
use App\Config\ApiEndpoint;
use App\Config\Backend;
use App\Config\MachineConfig;
use App\Config\ModelProfile;
use App\Config\ModelResolver;
use App\Platform\ModelInfo;
use App\Memory\MemoryStore;
use App\Platform\OllamaPlatform;
use App\Project\DockerConfig;
use App\Project\Project;
use App\Project\StackDetector;
use App\Skills\SkillRegistry;
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
        echo "        got: {$detail}\n";
    }
}

$dir = sys_get_temp_dir() . '/sherpa-model-' . bin2hex(random_bytes(4));
mkdir($dir, 0777, true);

function configAt(string $path): MachineConfig
{
    $c = new MachineConfig();
    $c->setPath($path);

    return $c;
}

// ---- 1. Never chosen ⇒ null, which is what triggers the question -----------
$config = configAt($dir . '/absent.yaml');
check('no file means no stored choice', $config->read() === null);

// ---- 2. Round trip ---------------------------------------------------------
$config = configAt($dir . '/config.yaml');
check('a choice is written', $config->save(new ModelProfile('qwen3-coder:30b', 65536)));

$stored = $config->read();
check('and read back intact',
    $stored?->model === 'qwen3-coder:30b' && $stored->contextWindow === 65536,
    json_encode([$stored?->model, $stored?->contextWindow]));
check('the file says where it came from', str_contains(file_get_contents($dir . '/config.yaml'), '/model'));

// Hand-editing is expected: the file is a config, not a database.
file_put_contents($dir . '/config.yaml', "model: llama3.1:8b\ncontext: 16384\n");
$stored = $config->read();
check('a hand-written file is honoured',
    $stored?->model === 'llama3.1:8b' && $stored->contextWindow === 16384,
    json_encode([$stored?->model, $stored?->contextWindow]));

// ---- 3. A file that says nothing usable reads as "never chosen" ------------
// Otherwise the setup screen never appears and the session runs against "".
foreach (['', "model: ''\n", "context: 4096\n", "{{ not yaml\n"] as $i => $content) {
    file_put_contents($dir . '/broken.yaml', $content);
    check("unusable file #{$i} reads as no choice", configAt($dir . '/broken.yaml')->read() === null);
}

// ---- 4. The precedence chain ----------------------------------------------
file_put_contents($dir . '/config.yaml', "model: stored:7b\ncontext: 8192\n");
$resolver = new ModelResolver(configAt($dir . '/config.yaml'), 'env:7b', 32768);

check('the command line wins', $resolver->fromCommandLine('cli:70b', null)?->model === 'cli:70b');
check('config.yaml beats .env', $resolver->stored()?->model === 'stored:7b');
check('.env is the last resort', $resolver->fallback()->model === 'env:7b');
check('no flags means no command-line profile', $resolver->fromCommandLine(null, null) === null);

// --ctx alone retunes what is already in force rather than dragging .env along.
$retuned = $resolver->fromCommandLine(null, 4096);
check('--ctx alone keeps the settled model',
    $retuned?->model === 'stored:7b' && $retuned->contextWindow === 4096,
    json_encode([$retuned?->model, $retuned?->contextWindow]));

$empty = new ModelResolver(configAt($dir . '/absent.yaml'), 'env:7b', 32768, 'ollama');
check('with nothing stored, --ctx falls back to .env', $empty->fromCommandLine(null, 4096)?->model === 'env:7b');

// ---- 4b. Two backends, one choice for the machine -------------------------
// The API is the default: it works on any machine the moment a key is set.
$fresh = new ModelResolver(configAt($dir . '/absent.yaml'), 'env:7b', 32768);
check('with nothing said anywhere, the API is the backend', $fresh->backend() === Backend::Api);
check('a flag wins over everything', $fresh->backend(Backend::Ollama) === Backend::Ollama);
check('.env can say otherwise',
    (new ModelResolver(configAt($dir . '/absent.yaml'), 'env:7b', 32768, 'ollama'))->backend() === Backend::Ollama);
check('and an unreadable .env value falls back to the default',
    (new ModelResolver(configAt($dir . '/absent.yaml'), 'env:7b', 32768, 'n-importe-quoi'))->backend() === Backend::Api);

// No provider's model can be guessed, so an unconfigured API has none — and
// the command explains what to set rather than sending a request to "".
check('an API with no model configured falls back to none', $fresh->fallback()->model === '');
$apiEnv = new ModelResolver(configAt($dir . '/absent.yaml'), 'env:7b', 32768, 'api', 'fournisseur/modele', 65536);
check('.env names the API model and window',
    $apiEnv->fallback()->model === 'fournisseur/modele' && $apiEnv->fallback()->contextWindow === 65536
    && $apiEnv->fallback()->backend === Backend::Api);
check('while Ollama keeps its own', $apiEnv->fallback(Backend::Ollama)->model === 'env:7b');
check('-m under -b belongs to that backend',
    $apiEnv->fromCommandLine('llama3.1:8b', null, Backend::Ollama)?->backend === Backend::Ollama);

// Each backend keeps its own model, so going back to one finds its choice.
$both = configAt($dir . '/both.yaml');
$both->save(new ModelProfile('distant', 65536, '', Backend::Api));
$both->save(new ModelProfile('local:7b', 16384, '', Backend::Ollama), asDefault: false);
check('the default backend is the one saved as default', $both->backend() === Backend::Api);
check('its model is what read() returns', $both->read()?->model === 'distant');
check('and the other backend keeps its own model',
    $both->read(Backend::Ollama)?->model === 'local:7b' && $both->read(Backend::Ollama)?->contextWindow === 16384);
check('a choice made under -b is kept without becoming the default', $both->backend() === Backend::Api);

$both->save(new ModelProfile('local:7b', 16384, '', Backend::Ollama));
check('remembering it as the default does switch', $both->backend() === Backend::Ollama);
check('without losing the API model', $both->read(Backend::Api)?->model === 'distant');

check('a backend chosen but never given a model reads as never chosen',
    (function () use ($dir) {
        file_put_contents($dir . '/half.yaml', "backend: api\nollama:\n  model: local:7b\n");

        return configAt($dir . '/half.yaml')->read() === null;
    })());

// A file from before there was a choice: a bare model, which can only have
// been an Ollama one. Reading it as the new default would point an existing
// install at an API it was never configured for.
file_put_contents($dir . '/legacy.yaml', "model: ancien:7b\ncontext: 8192\n");
$legacy = configAt($dir . '/legacy.yaml');
check('a file from before backends is read as Ollama', $legacy->backend() === Backend::Ollama);
check('with its model intact', $legacy->read()?->model === 'ancien:7b' && $legacy->read()?->contextWindow === 8192);
check('and it has no API model', $legacy->read(Backend::Api) === null);

$legacy->save(new ModelProfile('distant', 32768, '', Backend::Api), asDefault: false);
$migrated = Symfony\Component\Yaml\Yaml::parseFile($dir . '/legacy.yaml');
check('saving folds the old shape into its section',
    ($migrated['ollama']['model'] ?? null) === 'ancien:7b' && !isset($migrated['model']), json_encode($migrated));
check('keeps the machine on the backend it was using', $legacy->backend() === Backend::Ollama);
check('and adds the new one beside it', $legacy->read(Backend::Api)?->model === 'distant');

// ---- 4c. The API's address, asked for once and remembered ------------------
// What gets pasted is rarely the base URL: it is a curl example's endpoint, or
// it has a trailing slash, or no scheme. Each has one obvious reading.
check('a pasted endpoint is cut back to the base URL',
    ApiEndpoint::normalize('https://api.openai.com/v1/chat/completions') === 'https://api.openai.com/v1');
check('a trailing slash is dropped', ApiEndpoint::normalize('https://api.mistral.ai/v1/') === 'https://api.mistral.ai/v1');
check('a missing scheme means https', ApiEndpoint::normalize('openrouter.ai/api/v1') === 'https://openrouter.ai/api/v1');
check('except for a local server, which is plain http',
    ApiEndpoint::normalize('localhost:8000/v1') === 'http://localhost:8000/v1');
foreach (['', 'pas une adresse', 'ftp://example.com/v1', 'https://', 'http://exa mple.com'] as $bad) {
    check('not an http(s) address is refused: ' . json_encode($bad), ApiEndpoint::normalize($bad) === null);
}

$urlConfig = configAt($dir . '/url.yaml');
check('no address recorded reads as none', $urlConfig->apiUrl() === null);
$urlConfig->save(new ModelProfile('fournisseur/modele', 32768, '', Backend::Api));
check('an address is recorded', $urlConfig->saveApiUrl('https://api.example.com/v1') && $urlConfig->apiUrl() === 'https://api.example.com/v1');
check('without losing the model recorded beside it', $urlConfig->read(Backend::Api)?->model === 'fournisseur/modele');
$urlConfig->save(new ModelProfile('autre/modele', 65536, '', Backend::Api));
check('and choosing another model keeps the address', $urlConfig->apiUrl() === 'https://api.example.com/v1');
$written = (string) file_get_contents($dir . '/url.yaml');
check('the file says the key is never written there', str_contains($written, "SHERPA_API_KEY.\n"), $written);
check('and holds no key', !preg_match('/key\s*:/i', $written), $written);

$urlResolver = new ModelResolver($urlConfig, 'env:7b', 32768);
check('the recorded address is used when the environment says nothing',
    $urlResolver->apiUrl('') === 'https://api.example.com/v1');
check('an address in the environment wins — it ships empty, so it was chosen',
    $urlResolver->apiUrl('https://ailleurs.example/v1') === 'https://ailleurs.example/v1');
check('and with neither there is none', (new ModelResolver(configAt($dir . '/absent.yaml'), 'env:7b', 32768))->apiUrl('') === null);

// ---- 5. A suggested window never exceeds what the model has ----------------
$big = new ModelInfo('qwen3-coder:30b', 262144, ['completion', 'tools']);
$small = new ModelInfo('qwen2.5-coder:7b', 32768, ['completion', 'tools']);
$tiny = new ModelInfo('petit:1b', 4096, ['completion', 'tools']);
$unknown = new ModelInfo('jamais-vu:1b');

check('a huge model is proposed a safe window', ModelProfile::suggestFor($big)->contextWindow === ModelProfile::SAFE_CONTEXT);
check('a small model is never proposed more than it has', ModelProfile::suggestFor($tiny)->contextWindow === 4096);
check('an unknown window falls back to the safe one', ModelProfile::suggestFor($unknown)->contextWindow === ModelProfile::SAFE_CONTEXT);

// ---- 6. Tool support is the one disqualifying fact -------------------------
check('a tool-calling model passes', $small->supportsTools());
check('a model without tools is refused', !(new ModelInfo('vision-only:7b', 8192, ['completion', 'vision']))->supportsTools());
check('silence about capabilities is not evidence against', $unknown->supportsTools());
check('and silence is reported as silence', $unknown->capabilitiesUnknown());

// ---- 7. Switching models throws the calibration away -----------------------
// chars-per-token was learned from the previous tokenizer. Carrying it across
// means estimating the next model's context with the previous model's ruler.
$budget = new ContextBudget(contextWindow: 32768);
$budget->useModel('qwen2.5-coder:7b', 32768);

$messages = [['role' => 'user', 'content' => str_repeat('some english text ', 400)]];
$budget->calibrate(900, $messages);
check('the estimate calibrates', $budget->isCalibrated());
$learned = $budget->charsPerToken();

$budget->useModel('qwen2.5-coder:7b', 16384);
check('retuning the window keeps the calibration',
    $budget->isCalibrated() && $budget->charsPerToken() === $learned);
check('and the window actually moved', $budget->contextWindow() === 16384, (string) $budget->contextWindow());

$budget->useModel('devstral-small-2:24b', 65536);
check('changing model resets the calibration', !$budget->isCalibrated());
check('and the last measurement with it', $budget->lastMeasured() === null);
check('the new window is in force', $budget->contextWindow() === 65536, (string) $budget->contextWindow());

// ---- 8. The catalogue, read off /api/tags in one request -------------------
$tags = json_encode(['models' => [
    ['name' => 'qwen2.5-coder:7b', 'size' => 4700000000,
     'details' => ['parameter_size' => '7.6B', 'context_length' => 32768], 'capabilities' => ['completion', 'tools']],
    ['name' => 'qwen3-coder:30b', 'size' => 18556700761,
     'details' => ['parameter_size' => '30.5B', 'context_length' => 262144], 'capabilities' => ['completion', 'tools']],
    ['not-a-model' => true],
]]);

$platform = new OllamaPlatform(new MockHttpClient(new MockResponse($tags)), 'http://x', 'm');
$catalogue = $platform->catalogue();

check('malformed entries are skipped', count($catalogue) === 2, (string) count($catalogue));
check('largest first', $catalogue[0]->name === 'qwen3-coder:30b', $catalogue[0]->name ?? '');
check('the native window comes across', $catalogue[0]->contextLength === 262144, (string) $catalogue[0]->contextLength);
check('and so do the capabilities', $catalogue[0]->supportsTools());
check('sizes are made readable', $catalogue[0]->humanSize() === '19 GB', $catalogue[0]->humanSize());
check('windows are made readable', $catalogue[0]->humanContext() === '256k', $catalogue[0]->humanContext());

$offline = new OllamaPlatform(new MockHttpClient(new MockResponse('', ['http_code' => 500])), 'http://x', 'm');
check('an unreachable server yields an empty catalogue, not a crash', $offline->catalogue() === []);

// ---- 9. A model named rather than picked ----------------------------------
// This is what keeps the choice open to models Sherpa has never seen.
$show = json_encode([
    'capabilities' => ['completion', 'tools'],
    'details' => ['parameter_size' => '24.0B'],
    // The rope key ends in the same two words and means the length *before*
    // extension: reading it is how a 393k model gets treated as an 8k one.
    'model_info' => [
        'mistral3.rope.scaling.original_context_length' => 8192,
        'mistral3.context_length' => 393216,
    ],
]);

$named = (new OllamaPlatform(new MockHttpClient(new MockResponse($show)), 'http://x', 'm'))
    ->describeModel('devstral-small-2:24b');

check('an arbitrary name can be described', $named !== null);
check('the architecture-prefixed window is found', $named?->contextLength === 393216, (string) $named?->contextLength);
check('the rope-scaling window is not mistaken for it', $named?->contextLength !== 8192);

$missing = (new OllamaPlatform(
    new MockHttpClient(new MockResponse('{"error":"model not found"}', ['http_code' => 404])),
    'http://x',
    'm',
))->describeModel('jamais-pull:1b');
check('a model the server lacks reports absence, not failure', $missing === null);

// ---- 10. The platform follows the switch ----------------------------------
$platform = new OllamaPlatform(new MockHttpClient(new MockResponse($tags)), 'http://x', 'depart:7b', 32768);
check('it starts on the configured model', $platform->modelName() === 'depart:7b');
$platform->useModel('arrivee:30b', 65536);
check('and moves to the chosen one', $platform->modelName() === 'arrivee:30b', $platform->modelName());
check('the window travels with it', $platform->contextWindow() === 65536, (string) $platform->contextWindow());

$platform->useModel('', 0);
check('an empty switch changes nothing',
    $platform->modelName() === 'arrivee:30b' && $platform->contextWindow() === 65536);

// ---- 11. What a model change does to the session --------------------------
// The reset itself lives in SherpaCommand, behind a TTY. Its two invariants do
// not: what counts as discarded, and what the bag looks like afterwards.
$bag = new MessageBag();
$bag->system('SYSTEM PROMPT');
$bag->user('lis le controller');
$bag->add(['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'file_read', 'arguments' => []]]]]);
$bag->tool('file_read', '   1 | <?php');
$bag->assistant('voici ce qu\'il fait');

// The number shown to someone deciding whether to throw the conversation away.
// The system message is rebuilt rather than lost, so counting it would
// overstate the loss by one — every time, and in the one place it matters.
check('the discarded count excludes the system message',
    $bag->conversationSize() === 4, (string) $bag->conversationSize());
check('and it is not merely the message count', $bag->count() === 5, (string) $bag->count());

$bag->replace([]);
$bag->system('SYSTEM PROMPT REBUILT');

check('after the reset only the system message remains', $bag->count() === 1, (string) $bag->count());
check('and it is the rebuilt one',
    ($bag->all()[0]['role'] ?? '') === 'system' && ($bag->all()[0]['content'] ?? '') === 'SYSTEM PROMPT REBUILT',
    json_encode($bag->all()));

// This same number decides whether a confirmation is asked at all: zero means
// the switch costs nothing, and a question there is friction on the very action
// that was just requested.
$fresh = new MessageBag();
$fresh->system('PROMPT');
check('a session that never began has nothing to discard', $fresh->conversationSize() === 0);
check('and so is not worth a confirmation', $fresh->conversationSize() === 0);

$fresh->user('une seule question');
check('but one typed message already is', $fresh->conversationSize() === 1, (string) $fresh->conversationSize());

// Replacing the system prompt must not read as a conversation reappearing.
$fresh->replace([]);
$fresh->system('AUTRE PROMPT');
check('a rebuilt system prompt still counts as nothing at stake', $fresh->conversationSize() === 0);

// ---- 12. The memory block is a share of the window, not a fixed size ------
// A flat 1600 characters was a percentage of a 28k window written down as a
// constant; it stopped being that percentage the moment the window moved.
$memory = new MemoryStore();
$memory->open($dir . '/prompt.db');
for ($i = 0; $i < 60; $i++) {
    $memory->remember("convention_{$i}", str_repeat("a lasting, well-put fact {$i}. ", 4));
}

mkdir($dir . '/projet', 0777, true);
file_put_contents($dir . '/projet/go.mod', "module x\n\ngo 1.23\n");

$project = new Project(
    slug: 'x', name: 'exemple', path: $dir . '/projet', memoryDb: '/dev/null',
    docker: new DockerConfig(enabled: false), stack: '',
    createdAt: new \DateTimeImmutable(), lastUsedAt: new \DateTimeImmutable(),
);

function promptFor(MemoryStore $memory, ?ContextBudget $budget): string
{
    return (new SystemPromptBuilder($memory, new SkillRegistry(), new StackDetector(), $budget))
        ->build($GLOBALS['project']);
}
$GLOBALS['project'] = $project;

$small = promptFor($memory, new ContextBudget(contextWindow: 32768));
$large = promptFor($memory, new ContextBudget(contextWindow: 262144));
$none  = promptFor($memory, null);

check('a big window carries more remembered facts', mb_strlen($large) > mb_strlen($small),
    mb_strlen($large) . ' vs ' . mb_strlen($small));
check('a small window is unchanged by the new rule', mb_strlen($small) === mb_strlen($none),
    mb_strlen($small) . ' vs ' . mb_strlen($none));

// The ceiling is what stops a quarter-million-token window from spending
// thousands of tokens on facts nobody has asked for yet.
$huge = promptFor($memory, new ContextBudget(contextWindow: 1048576));
check('and the ceiling holds', mb_strlen($huge) === mb_strlen($large),
    mb_strlen($huge) . ' vs ' . mb_strlen($large));

// Whatever the window, what does not fit is still named rather than dropped.
check('the overflow index survives both ends',
    str_contains($small, 'memory_recall') && str_contains($large, 'memory_recall'));

array_map('unlink', glob($dir . '/projet/*') ?: []);
@rmdir($dir . '/projet');
array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
