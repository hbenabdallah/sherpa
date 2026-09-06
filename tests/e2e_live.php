<?php
/**
 * End-to-end check against a LIVE Ollama model.
 *
 * Not picked up by tests/run.php (which globs *_test.php) because it needs a
 * running Ollama and takes minutes on CPU. Run explicitly:
 *     docker compose run --rm --no-deps app php tests/e2e_live.php
 *
 * Only AUTO-permission tools are registered, so the run never blocks on the
 * confirmation overlay waiting for a keypress.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\AgentLoop;
use App\Agent\ContextBudget;
use App\Agent\HistoryCompactor;
use App\Agent\MessageBag;
use App\Agent\Tool\Toolbox;
use App\Permission\PermissionBroker;
use App\Permission\SessionPermissions;
use App\Platform\OllamaPlatform;
use App\Project\ProjectPathResolver;
use App\Tool\FileReadTool;
use App\Tool\ProjectGrepTool;
use App\TUI\ChatPane;
use App\TUI\ConfirmOverlay;
use App\TUI\MarkdownRenderer;
use App\TUI\Terminal;
use Symfony\Component\HttpClient\HttpClient;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo "        ", substr($detail, 0, 400), "\n";
    }
}

// ---- a throwaway project with a distinctive, unguessable symbol ------------
$project = sys_get_temp_dir() . '/sherpa-e2e-' . bin2hex(random_bytes(3));
mkdir($project . '/src', 0777, true);
file_put_contents($project . '/src/Widget.php', <<<'PHP'
<?php

namespace Demo;

class ZorbulonCalculator
{
    public function computeZorbulon(int $n): int
    {
        return $n * 42;
    }
}
PHP);

$model = getenv('OLLAMA_MODEL') ?: 'qwen2.5-coder:7b';
$url = getenv('OLLAMA_URL') ?: 'http://localhost:11434';

$platform = new OllamaPlatform(HttpClient::create(), $url, $model, 32768);
check('ollama reachable', $platform->isAvailable());
$names = array_map(fn($m) => $m->name, $platform->catalogue());
check('model present', in_array($model, $names, true), implode(',', $names));

$paths = new ProjectPathResolver();
$paths->setRoot($project);

$read = new FileReadTool($paths);
$grep = new ProjectGrepTool($paths);

$toolbox = new Toolbox([$read, $grep]);
$chat = new ChatPane(new Terminal(), new MarkdownRenderer());
$broker = new PermissionBroker(
    new SessionPermissions(),
    new ConfirmOverlay(new Terminal(), new App\Tool\FileWriteTool(), new App\Tool\FilePatchTool(), new App\Tool\ShellExecTool()),
    // No project bound: nothing is pre-authorised, and only AUTO tools are
    // registered below, so the overlay is never reached anyway.
    new App\Permission\ProjectPermissions(new App\Project\ProjectStore(new App\Project\StackDetector())),
);

$budget = new ContextBudget(contextWindow: 32768);
$loop = new AgentLoop(
    $platform,
    $toolbox,
    $broker,
    $chat,
    $budget,
    new HistoryCompactor($platform, $budget),
    new App\Runtime\Interrupt(),
);

$bag = new MessageBag();
$bag->system(
    "Tu es un agent de développement PHP. Le projet est à {$project}. " .
    'Utilise TOUJOURS le tool file_read pour lire un fichier avant de répondre. ' .
    'Ne devine jamais le contenu.'
);
$bag->user('Lis le fichier src/Widget.php et donne-moi exactement le nom de la classe qui y est définie.');

echo "\n--- live run (CPU inference, be patient) ---\n";
$started = microtime(true);

ob_start();
$result = $loop->run($bag);
$transcript = ob_get_clean();

$elapsed = microtime(true) - $started;
echo $transcript;
printf("\n--- finished in %.1fs, %d iteration(s) ---\n\n", $elapsed, $result->iterations);

check('loop terminated without hitting the iteration cap', !$result->maxIterationsReached);
check('a tool was actually invoked', str_contains($transcript, 'Tool:'), $transcript);
check('file_read specifically was invoked', str_contains($transcript, 'file_read'), $transcript);
check(
    'answer contains the class name only readable from disk',
    str_contains($result->content, 'ZorbulonCalculator'),
    'answer: ' . $result->content,
);
check('final answer is non-empty', trim($result->content) !== '');

// The tool result must have been folded back into the conversation.
$roles = array_column($bag->all(), 'role');
check('a tool message was appended to history', in_array('tool', $roles, true), implode(',', $roles));

// ---- the estimator, measured against the server's own tokenizer -----------
$measured = $budget->lastMeasured();
check('server reported a real prompt token count', $measured !== null, var_export($platform->lastUsage(), true));
check('the budget calibrated itself from it', $budget->isCalibrated());

if ($measured !== null) {
    $estimate = $budget->estimateRequest($bag->all(), array_map(
        fn($d) => $d->toFunctionSchema(),
        $toolbox->getDefinitions(),
    ));
    $error = abs($estimate - $measured) / $measured;

    printf(
        "    estimate %d vs measured %d  (%.0f%% off, %.2f chars/token)\n",
        $estimate,
        $measured,
        $error * 100,
        $budget->charsPerToken(),
    );

    // The estimate drives a compaction decision, not a hard limit, so it only
    // has to be in the right neighbourhood.
    check('estimate lands within 40% of the real count', $error < 0.40, sprintf('%.0f%% off', $error * 100));
}

exec('rm -rf ' . escapeshellarg($project));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
