<?php
// The first-run question about the API, run on its own with its answers piped
// in, as a person would type them. Used by first_run_test.php.
//
//   php tests/fixtures/first_run.php <config-dir>   (answers on stdin)

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Agent\ContextBudget;
use App\Agent\InferenceSpeed;
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

$dir = $argv[1];
$sent = [];
$memory = new MemoryStore();
$memory->open(':memory:');
$context = new ContextStore();
$context->open();
// Answers read line by line from the pipe, as a terminal would hand them over:
// what is under test is the questions asked, not the line editor.
$editor = new class extends LineEditor {
    public function isInteractive(): bool
    {
        return true;
    }

    public function ask(string $label, string $colour = ''): string
    {
        echo $label;

        return trim((string) fgets(STDIN));
    }

    public function askSecret(string $label, string $colour = ''): string
    {
        echo $label . "\n";

        return trim((string) fgets(STDIN));
    }
};
$api = new OpenAiCompatiblePlatform(
    new MockHttpClient(function (string $method, string $url, array $options) use (&$sent) {
        foreach ($options['headers'] ?? [] as $header) {
            if (stripos($header, 'authorization:') === 0) {
                $sent[] = trim(substr($header, strlen('authorization:')));
            }
        }

        return new MockResponse('{"data":[]}', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
    }),
    '',
    'm',
    (string) getenv('SHERPA_API_KEY'),
);
$platform = new SwitchablePlatform(new OllamaPlatform(new MockHttpClient(), 'http://ollama.test', 'x'), $api);
$platform->switchTo(Backend::Api);
$config = new MachineConfig();
$config->setPath($dir . '/config.yaml');
$models = new ModelResolver($config, 'x', 16384, 'api', '', 32768);
$session = new ModelSession(
    $platform, $models, new ModelSelector(new Terminal(), $editor, $platform),
    new ContextBudget(), new InferenceSpeed(), new UsageMeter(), new ChatPane(new Terminal(), new MarkdownRenderer()), $editor,
    new SystemPromptBuilder($memory, new SkillRegistry(), new StackDetector()), $context, new Toolbox([]), new SessionStore(),
);

$session->configureApiEndpoint();
echo "\nSENT: " . implode(' | ', $sent) . "\n";
