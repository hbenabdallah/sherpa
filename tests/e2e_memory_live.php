<?php
/**
 * End-to-end check of project memory against a LIVE Ollama model.
 *
 * Not picked up by tests/run.php (which globs *_test.php) because it needs a
 * running Ollama and takes minutes on CPU. Run explicitly:
 *     docker compose run --rm --no-deps app php tests/e2e_memory_live.php
 *
 * memory_test.php proves the store. This proves the loop around it: that a
 * small local model actually reaches for these tools, and that what it writes
 * in one conversation is still findable from another. Until this ran, the
 * feature had never persisted a single fact outside a unit test.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\AgentLoop;
use App\Agent\ContextBudget;
use App\Agent\HistoryCompactor;
use App\Agent\MessageBag;
use App\Agent\Tool\Toolbox;
use App\Memory\MemoryStore;
use App\Permission\PermissionBroker;
use App\Permission\ProjectPermissions;
use App\Permission\SessionPermissions;
use App\Platform\OllamaPlatform;
use App\Project\ProjectStore;
use App\Project\StackDetector;
use App\Runtime\Interrupt;
use App\Tool\FilePatchTool;
use App\Tool\FileWriteTool;
use App\Tool\MemoryRecallTool;
use App\Tool\MemoryRememberTool;
use App\Tool\ShellExecTool;
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

$dir = sys_get_temp_dir() . '/sherpa-mem-live-' . bin2hex(random_bytes(3));
$store = new MemoryStore();
$store->open($dir . '/memory.db');

$model = getenv('OLLAMA_MODEL') ?: 'qwen2.5-coder:7b';
$platform = new OllamaPlatform(
    HttpClient::create(),
    getenv('OLLAMA_URL') ?: 'http://localhost:11434',
    $model,
    32768,
    -1.0,
    new Interrupt(),
);
check('ollama reachable', $platform->isAvailable());

// Only AUTO-permission tools, so nothing blocks on the confirmation overlay.
$toolbox = new Toolbox([new MemoryRememberTool($store), new MemoryRecallTool($store)]);
$budget = new ContextBudget(contextWindow: 32768);
$loop = new AgentLoop(
    $platform,
    $toolbox,
    new PermissionBroker(
        new SessionPermissions(),
        new ConfirmOverlay(new Terminal(), new FileWriteTool(), new FilePatchTool(), new ShellExecTool()),
        new ProjectPermissions(new ProjectStore(new StackDetector())),
    ),
    new ChatPane(new Terminal(), new MarkdownRenderer()),
    $budget,
    new HistoryCompactor($platform, $budget),
    new Interrupt(),
);

/** @return array{0: string, 1: string} transcript and final answer */
function turn(AgentLoop $loop, MessageBag $bag, string $text): array
{
    $bag->user($text);
    $started = microtime(true);

    ob_start();
    $result = $loop->run($bag);
    $transcript = ob_get_clean();

    printf("    (%.0fs, %d iteration(s))\n", microtime(true) - $started, $result->iterations);

    return [$transcript, $result->content];
}

echo "\n--- writing (CPU inference, be patient) ---\n";
$writing = new MessageBag();
$writing->system(
    'Tu es un agent de développement. Tu disposes de memory_remember et memory_recall '
    . 'pour retenir des faits durables sur le projet entre les sessions. '
    . 'Utilise TOUJOURS ces tools plutôt que de répondre de mémoire.'
);
[$transcript] = turn($loop, $writing, "Retiens ceci pour les prochaines sessions : la base de données de ce projet est PostgreSQL 16, et le port d'écoute est 5433.");

check('the model reached for memory_remember', str_contains($transcript, 'memory_remember'), $transcript);

$facts = $store->recall();
check('something was actually persisted', $facts !== [], 'nothing in the store');
check(
    'the persisted facts carry the detail that was given',
    str_contains(json_encode($facts), '5433'),
    json_encode($facts, JSON_UNESCAPED_UNICODE),
);

echo "\n--- reading it back from a separate conversation ---\n";
// A new MessageBag is the point: nothing below has seen the exchange above, so
// anything recovered came out of the database and not out of the context.
$reading = new MessageBag();
$reading->system(
    'Tu es un agent de développement. Utilise memory_recall pour retrouver ce qui a été '
    . 'mémorisé sur ce projet. Ne devine jamais.'
);
[$transcript, $answer] = turn($loop, $reading, 'Quel port utilise la base de données de ce projet ? Cherche dans ta mémoire.');

check('the model reached for memory_recall', str_contains($transcript, 'memory_recall'), $transcript);
check('the answer comes back with the remembered port', str_contains($answer, '5433'), 'answer: ' . $answer);

echo "\n--- reaching for a fact the prompt only names ---\n";
// The system prompt quotes as many facts as its budget allows and lists the
// keys of the rest. That is only worth doing if the model actually follows the
// key: the whole design rests on it choosing to look something up rather than
// answering from what it can see. Put the answer out of sight and ask for it.
$store->remember('port_metrics', 'Le serveur de métriques Prometheus écoute sur le port 9187.');
for ($i = 0; $i < 30; $i++) {
    // Written after, so these fill the quoted block and push port_metrics into
    // the index line, where only its key survives.
    $store->remember("convention_{$i}", "Convention interne numéro {$i}, décrite avec assez de détail pour occuper une ligne entière du digest.");
}

$digest = $store->digest();
check('the answer is out of sight, only its key is listed',
    !str_contains($digest, '9187') && str_contains($digest, 'port_metrics'), $digest);

$looking = new MessageBag();
$looking->system(
    "# Mémoire permanente (faits durables sur ce projet)\n{$digest}\n\n"
    . "Cette liste peut être partielle. Utilise memory_recall(search) pour retrouver un "
    . "fait dont seule la clé est citée ci-dessus, ou pour chercher par mots-clés.\n"
    . 'Ne devine jamais : lis la mémoire avant de répondre.'
);
[$transcript, $answer] = turn($loop, $looking, 'Sur quel port écoute le serveur de métriques ?');

check('the model followed the key into memory_recall', str_contains($transcript, 'memory_recall'), $transcript);
check('and answered with a value it could not see in its prompt', str_contains($answer, '9187'), 'answer: ' . $answer);

exec('rm -rf ' . escapeshellarg($dir));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
