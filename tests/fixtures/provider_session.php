<?php
// Runs one /provider command against scripted providers, answering its
// questions from stdin, and prints what happened as JSON — so adding and
// switching providers can be tested without a terminal.
// Usage: php provider_session.php <config.yaml> "<args of /provider>"
// Every provider answers /models; one without the Authorization header its
// test expects answers 401, as a real one would.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Agent\ContextBudget;
use App\Agent\InferenceSpeed;
use App\Agent\MessageBag;
use App\Agent\SystemPromptBuilder;
use App\Agent\Tool\Toolbox;
use App\Command\ModelSession;
use App\Config\Backend;
use App\Config\MachineConfig;
use App\Config\ModelResolver;
use App\Memory\ContextStore;
use App\Memory\MemoryStore;
use App\Platform\OllamaPlatform;
use App\Platform\OpenAiCompatiblePlatform;
use App\Platform\SwitchablePlatform;
use App\Project\DockerConfig;
use App\Project\Project;
use App\Project\StackDetector;
use App\Session\SessionStore;
use App\Skills\SkillRegistry;
use App\TUI\ChatPane;
use App\TUI\LineEditor;
use App\TUI\MarkdownRenderer;
use App\TUI\ModelSelector;
use App\TUI\Terminal;
use App\Usage\UsageMeter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

[, $configPath, $args] = $argv;
$dir = dirname($configPath);

$requests = [];
$http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
    $auth = '';
    foreach ($options['headers'] ?? [] as $header) {
        if (stripos($header, 'Authorization:') === 0) {
            $auth = trim(substr($header, strlen('Authorization:')));
        }
    }
    $requests[] = ['url' => $url, 'auth' => $auth];

    // A provider refuses a request without a key; the model it is asked about
    // exists, with tools and a large window.
    if ($auth === '') {
        return new MockResponse('{"error":"no key"}', ['http_code' => 401]);
    }
    if (preg_match('#/models/(.+)$#', $url, $m)) {
        return new MockResponse(json_encode(['id' => rawurldecode($m[1]), 'context_length' => 131072]), ['http_code' => 200]);
    }

    return new MockResponse('{"data":[]}', ['http_code' => 200]);
});

$config = new MachineConfig();
$config->setPath($configPath);
$models = new ModelResolver($config, 'ollama:7b', 16384, 'api');
$api = new OpenAiCompatiblePlatform($http, '', '', (string) getenv('SHERPA_API_KEY'));
$platform = new SwitchablePlatform(
    new OllamaPlatform(new MockHttpClient(new MockResponse('', ['http_code' => 503])), 'http://ollama.test', 'local:7b'),
    $api,
);

$memory = new MemoryStore();
$memory->open($dir . '/memory-' . bin2hex(random_bytes(3)) . '.db');
$editor = new LineEditor();
$context = new ContextStore();
$context->open();
$session = new ModelSession(
    $platform, $models, new ModelSelector(new Terminal(), $editor, $platform),
    new ContextBudget(contextWindow: 32768), new InferenceSpeed(), new UsageMeter(),
    new ChatPane(new Terminal(), new MarkdownRenderer()), $editor,
    new SystemPromptBuilder($memory, new SkillRegistry(), new StackDetector()),
    $context, new Toolbox([]), new SessionStore(),
);
$project = new Project(
    slug: 'essai', name: 'Essai', path: $dir, memoryDb: $dir . '/memory.db',
    docker: new DockerConfig(enabled: false), stack: '',
    createdAt: new DateTimeImmutable(), lastUsedAt: new DateTimeImmutable(),
);

// As at boot: the machine's provider, its address and its key.
ob_start();
$platform->switchTo(Backend::Api);
$session->configureApiEndpoint();
if (($stored = $models->stored(Backend::Api)) !== null) {
    $session->apply($stored);
}
$requests = [];
$bag = new MessageBag();
$bag->system('PROMPT');
$session->handleProvider($args, $bag, $project);
$shown = (string) ob_get_clean();

echo json_encode([
    'shown'    => Terminal::plain($shown),
    'endpoint' => $platform->apiEndpoint(),
    'backend'  => $platform->backend()->value,
    'model'    => $session->profile()?->model,
    'requests' => $requests,
]);
