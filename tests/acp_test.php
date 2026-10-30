<?php

declare(strict_types=1);

namespace App\Tests\AcpTest;

use Closure;
use stdClass;

// `sherpa acp` end to end, as an editor drives it: the real command in a
// subprocess, a scripted provider behind it, and this script in the editor's
// seat — initialize, open a session, prompt, answer the permission questions,
// cancel, load the conversation back in a fresh process.

require __DIR__ . '/../vendor/autoload.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo "        ", str_replace("\n", "\n        ", substr($detail, 0, 600)), "\n";
    }
}

$work = sys_get_temp_dir() . '/sherpa-acp-' . bin2hex(random_bytes(4));
mkdir("{$work}/home", 0777, true);
mkdir("{$work}/project", 0777, true);
file_put_contents("{$work}/project/Invoice.php", "<?php\nfunction subtotal(): int\n{\n    return 0;\n}\n");
mkdir("{$work}/project/.sherpa/skills", 0777, true);
file_put_contents("{$work}/project/.sherpa/skills/greet.md", "Greet the user warmly.\n\nSay hello, by name if known.\n");
$project = realpath("{$work}/project");

$port = 21000 + getmypid() % 9000;
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/acp_provider.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $serverPipes, null, ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv());
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(100_000);
}

/** The editor's side of one `sherpa acp` process. */
final class Editor
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $partial = '';

    private int $id = 100;

    /** @var list<string> what came out on stdout that was not a JSON-RPC message */
    public array $stray = [];

    /** @var list<array<string, mixed>> every notification received, in order */
    public array $updates = [];

    /** @var list<array<string, mixed>> permission questions asked, with the answer given */
    public array $asked = [];

    /** @var Closure(array<string, mixed>): ?string picks an option id for a question, null to cancel */
    public Closure $answer;

    /** @var array<string, string> files open in tabs, with changes not saved: path => text */
    public array $buffers = [];

    /** @var list<string> paths Sherpa asked the editor to read */
    public array $read = [];

    /** Answer every read with an error, as an editor that cannot give the file. */
    public bool $refuseReads = false;

    /** @var array<string, string> what Sherpa asked the editor to write: path => text */
    public array $written = [];

    /** @param string|null $apiUrl null: nothing set up at all */
    public function __construct(string $work, ?string $apiUrl, string $home = 'home', bool $facts = false)
    {
        $env = array_filter([
            'PATH'                   => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME'                   => "{$work}/{$home}",
            'SHERPA_API_URL'         => $apiUrl,
            'SHERPA_API_KEY'         => $apiUrl === null ? null : 'test',
            'SHERPA_API_MODEL'       => $apiUrl === null ? null : 'fake-chat',
            'SHERPA_BACKEND'         => 'api',
            'SHERPA_FACT_EXTRACTION' => $facts ? 'on' : 'off',
        ], static fn(?string $value): bool => $value !== null);
        $this->process = proc_open([PHP_BINARY, __DIR__ . '/../bin/sherpa', 'acp'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', "{$work}/stderr.log", 'a'],
        ], $this->pipes, dirname(__DIR__), $env);
        stream_set_blocking($this->pipes[1], false);
        $this->answer = static fn(array $question): string => 'allow-once';
    }

    /** @param array<string, mixed> $params */
    public function notify(string $method, array $params): void
    {
        fwrite($this->pipes[0], json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params]) . "\n");
    }

    /**
     * Send a request and wait for its answer, answering Sherpa's own
     * questions meanwhile. $during runs on every notification.
     *
     * @param array<string, mixed>                       $params
     * @param (Closure(array<string, mixed>): void)|null $during
     *
     * @return array<string, mixed> the response, result or error
     */
    public function call(string $method, array $params, float $timeout = 20.0, ?Closure $during = null): array
    {
        $id = ++$this->id;
        fwrite($this->pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params === [] ? new stdClass() : $params]) . "\n");

        $deadline = microtime(true) + $timeout;
        $response = null;
        while (microtime(true) < $deadline) {
            // The whole batch read, even past the response: what follows it
            // in the same read is the next thing the editor would see.
            foreach ($this->read() as $message) {
                if (($message['id'] ?? null) === $id && !isset($message['method'])) {
                    $response = $message;
                    continue;
                }
                if (($message['method'] ?? '') === 'session/request_permission') {
                    $option = ($this->answer)($message['params']);
                    $this->asked[] = $message['params'] + ['answered' => $option];
                    fwrite($this->pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['outcome' => $option === null
                        ? ['outcome' => 'cancelled']
                        : ['outcome' => 'selected', 'optionId' => $option]]]) . "\n");
                } elseif (($message['method'] ?? '') === 'fs/read_text_file') {
                    $path = $message['params']['path'];
                    $this->read[] = $path;
                    $text = $this->refuseReads ? null : ($this->buffers[$path] ?? (is_file($path) ? file_get_contents($path) : null));
                    fwrite($this->pipes[0], json_encode($text === null
                        ? ['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32002, 'message' => 'Resource not found']]
                        : ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['content' => $text]]) . "\n");
                } elseif (($message['method'] ?? '') === 'fs/write_text_file') {
                    $this->written[$message['params']['path']] = $message['params']['content'];
                    $this->buffers[$message['params']['path']] = $message['params']['content'];
                    fwrite($this->pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]) . "\n");
                } elseif (isset($message['method'])) {
                    $this->updates[] = $message;
                    if ($during instanceof Closure) {
                        $during($message);
                    }
                }
            }
            if ($response !== null) {
                return $response;
            }
            usleep(5_000);
        }

        return ['error' => ['message' => "no answer to {$method} within {$timeout}s"]];
    }

    /**
     * The updates of one kind, since $from.
     *
     * @return list<array<string, mixed>>
     */
    public function of(string $kind, int $from = 0): array
    {
        return array_values(array_filter(array_slice($this->updates, $from), static fn(array $m): bool => ($m['params']['update']['sessionUpdate'] ?? '') === $kind));
    }

    /** The text of every chunk of one kind since $from, joined. */
    public function text(string $kind, int $from = 0): string
    {
        return implode('', array_map(static fn(array $m): string => (string) ($m['params']['update']['content']['text'] ?? ''), $this->of($kind, $from)));
    }

    public function close(): void
    {
        fclose($this->pipes[0]);
        $deadline = microtime(true) + 5;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
            usleep(20_000);
        }
        proc_terminate($this->process);
        proc_close($this->process);
    }

    /** @return list<array<string, mixed>> */
    private function read(): array
    {
        $bytes = fread($this->pipes[1], 65536);
        if ($bytes === false || $bytes === '') {
            return [];
        }

        $this->partial .= $bytes;
        $messages = [];
        while (($end = strpos($this->partial, "\n")) !== false) {
            $line = substr($this->partial, 0, $end);
            $this->partial = substr($this->partial, $end + 1);
            $message = json_decode($line, true);
            if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
                $this->stray[] = $line;
                continue;
            }
            $messages[] = $message;
        }

        return $messages;
    }
}

foreach (['bin/sherpa', 'bin/sherpa-release.php'] as $entry) {
    check(
        "{$entry} runs `sherpa acp` as its own command, not as the session's options",
        preg_match("/in_array\\(\\\$_SERVER\\['argv'\\]\\[1\\] \\?\\? '', \\[[^]]*'acp'/", (string) file_get_contents(__DIR__ . '/../' . $entry)) === 1,
    );
}

$api = "http://127.0.0.1:{$port}/v1";
$editor = new Editor($work, $api);

// ---- initialize ------------------------------------------------------------------
$init = $editor->call('initialize', ['protocolVersion' => 1, 'clientCapabilities' => ['fs' => ['readTextFile' => false, 'writeTextFile' => false]], 'clientInfo' => ['name' => 'test']], 60.0);
$result = $init['result'] ?? [];
check('initialize answers with protocol version 1', ($result['protocolVersion'] ?? null) === 1, json_encode($init));
check('and says it is Sherpa', ($result['agentInfo']['name'] ?? '') === 'sherpa' && ($result['agentInfo']['version'] ?? '') !== '');
check(
    'and that it can load sessions and take embedded files',
    ($result['agentCapabilities']['loadSession'] ?? false) === true && ($result['agentCapabilities']['promptCapabilities']['embeddedContext'] ?? false) === true,
);

// ---- a session -------------------------------------------------------------------
check(
    'a session in the home directory is refused, with the reason',
    str_contains((string) ($editor->call('session/new', ['cwd' => "{$work}/home", 'mcpServers' => []])['error']['message'] ?? ''), 'home directory'),
);

$new = $editor->call('session/new', ['cwd' => $project, 'mcpServers' => []]);
$session = (string) ($new['result']['sessionId'] ?? '');
check('session/new opens a session in the project', preg_match('/^\d{8}-\d{6}-[0-9a-f]{4}$/', $session) === 1, json_encode($new));

// ---- a plain answer ---------------------------------------------------------------
$from = count($editor->updates);
$reply = $editor->call('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'Say hello']]]);
check('a prompt ends its turn', ($reply['result']['stopReason'] ?? '') === 'end_turn', json_encode($reply));
$info = $editor->of('session_info_update', $from)[0]['params']['update'] ?? [];
check('the conversation is named after its first request', ($info['title'] ?? '') === 'Say hello', json_encode($info));
$said = $editor->text('agent_thought_chunk', $from);
check(
    'a new project says, at its first prompt, where its commands run, and how to change it',
    str_contains($said, 'New project: its commands run on this machine') && str_contains($said, '/project edit'),
    $said,
);
check('with the reply streamed as message chunks', $editor->text('agent_message_chunk', $from) === 'Hello from the fake model.', $editor->text('agent_message_chunk', $from));
$chunks = $editor->of('agent_message_chunk', $from);
check('one message id across its chunks', count($chunks) === 2 && $chunks[0]['params']['update']['messageId'] === $chunks[1]['params']['update']['messageId']);
$usage = $editor->of('usage_update', $from);
check('and how full the context is, after the turn', count($usage) === 1 && $usage[0]['params']['update']['size'] > 0 && $usage[0]['params']['update']['used'] > 0, json_encode($usage));

// ---- an image -----------------------------------------------------------------------
check('Sherpa says it takes images', ($init['result']['agentCapabilities']['promptCapabilities']['image'] ?? false) === true);
$png = base64_encode((string) hex2bin('89504e470d0a1a0a0000000d49484452'));
$from = count($editor->updates);
$editor->call('session/prompt', ['sessionId' => $session, 'prompt' => [
    ['type' => 'text', 'text' => 'What is on this screenshot?'],
    ['type' => 'image', 'mimeType' => 'image/png', 'data' => $png],
]]);
check('an image goes to the model with the text', $editor->text('agent_message_chunk', $from) === 'Saw 1 image(s) with: What is on this screenshot?', $editor->text('agent_message_chunk', $from));
$files = array_filter(glob("{$work}/home/.config/sherpa/projects/*/sessions/*.json") ?: [], static fn(string $f): bool => str_contains((string) file_get_contents($f), 'screenshot'));
$kept = (string) file_get_contents((string) reset($files));
check('the conversation is saved without the image\'s data, saying one was there', $files !== [] && !str_contains($kept, $png) && str_contains($kept, 'an image was attached here'), implode(', ', $files));

// ---- an edit, allowed -------------------------------------------------------------
$from = count($editor->updates);
$reply = $editor->call('session/prompt', ['sessionId' => $session, 'prompt' => [
    ['type' => 'text', 'text' => 'Patch '],
    ['type' => 'resource_link', 'uri' => "file://{$project}/Invoice.php", 'name' => 'Invoice.php'],
]]);
$question = $editor->asked[0] ?? [];
$diff = $question['toolCall']['content'][0] ?? [];
check('an edit asks first', count($editor->asked) === 1 && ($question['toolCall']['kind'] ?? '') === 'edit', json_encode($editor->asked));
check(
    'with the diff for the editor to draw: whole file before and after, absolute path',
    ($diff['type'] ?? '') === 'diff' && $diff['path'] === "{$project}/Invoice.php"
    && str_contains($diff['oldText'], 'return 0;') && str_contains($diff['newText'], 'return 42;') && !str_contains($diff['newText'], 'return 0;'),
    json_encode($diff),
);
check(
    'and the four answers the terminal offers',
    array_column($question['options'] ?? [], 'kind') === ['allow_once', 'allow_always', 'allow_always', 'reject_once'],
    json_encode($question['options'] ?? null),
);
$card = $editor->of('tool_call', $from)[0]['params']['update'] ?? [];
check(
    'the call is shown before it runs, titled, with the file it touches',
    ($card['title'] ?? '') === 'Edit Invoice.php' && ($card['status'] ?? '') === 'pending' && ($card['locations'][0]['path'] ?? '') === "{$project}/Invoice.php",
    json_encode($card),
);
check('the same id as the question', ($card['toolCallId'] ?? 'a') === ($question['toolCall']['toolCallId'] ?? 'b'));
$done = $editor->of('tool_call_update', $from)[0]['params']['update'] ?? [];
check('allowed, it runs: completed, with its diff', ($done['status'] ?? '') === 'completed' && ($done['content'][0]['type'] ?? '') === 'diff', json_encode($done));
check('and the file is changed', str_contains((string) file_get_contents("{$project}/Invoice.php"), 'return 42;'));
check('the model is told, and answers', str_starts_with($editor->text('agent_message_chunk', $from), 'Done: Patched: Invoice.php'), $editor->text('agent_message_chunk', $from));

// ---- an edit, refused -------------------------------------------------------------
$editor->answer = static fn(array $question): string => 'reject';
$from = count($editor->updates);
$editor->call('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'Patch it again']]]);
$refused = $editor->of('tool_call_update', $from)[0]['params']['update'] ?? [];
check('refused, it fails and does not run', ($refused['status'] ?? '') === 'failed' && str_contains((string) file_get_contents("{$project}/Invoice.php"), 'return 42;'), json_encode($refused));
check('with no diff claimed', !in_array('diff', array_column($refused['content'] ?? [], 'type'), true));

// ---- the plan ---------------------------------------------------------------------
$from = count($editor->updates);
$editor->call('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'Make a plan']]]);
$plan = $editor->of('plan', $from)[0]['params']['update']['entries'] ?? [];
check('the plan is the editor\'s own list', array_column($plan, 'status') === ['completed', 'in_progress', 'pending'] && ($plan[1]['content'] ?? '') === 'Fix subtotal()', json_encode($plan));
check('and no tool card for it', $editor->of('tool_call', $from) === []);

// ---- the mode and the model, beside the prompt box ----------------------------------
$modes = $new['result']['modes'] ?? [];
check('a session says its modes: default, plan and auto', ($modes['currentModeId'] ?? '') === 'default'
    && array_column($modes['availableModes'] ?? [], 'id') === ['default', 'plan', 'auto'], json_encode($modes));
$options = array_column($new['result']['configOptions'] ?? [], null, 'id');
check('and offers the mode and the model as options', array_keys($options) === ['mode', 'model']
    && ($options['mode']['category'] ?? '') === 'mode' && ($options['model']['category'] ?? '') === 'model', json_encode($options));
check('the model in use, among the provider\'s', ($options['model']['currentValue'] ?? '') === 'fake-chat'
    && array_column($options['model']['options'] ?? [], 'value') === ['fake-chat', 'fake-other'], json_encode($options['model'] ?? null));

$set = $editor->call('session/set_config_option', ['sessionId' => $session, 'configId' => 'model', 'value' => 'fake-other']);
$model = array_column($set['result']['configOptions'] ?? [], null, 'id')['model'] ?? [];
check('another model chosen is the current one', ($model['currentValue'] ?? '') === 'fake-other', json_encode($set));
$from = count($editor->updates);
$editor->call('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'Which model are you?']]]);
check('and the next turn runs on it', $editor->text('agent_message_chunk', $from) === 'Model: fake-other', $editor->text('agent_message_chunk', $from));
check(
    'a model the provider does not offer is refused',
    ($editor->call('session/set_config_option', ['sessionId' => $session, 'configId' => 'model', 'value' => 'gpt-imaginary'])['error']['code'] ?? 0) === -32602,
);

$third = $editor->call('session/new', ['cwd' => $project, 'mcpServers' => []]);
$thirdId = (string) ($third['result']['sessionId'] ?? '');
check(
    'a session opened next starts on the model last chosen',
    (array_column($third['result']['configOptions'] ?? [], null, 'id')['model']['currentValue'] ?? '') === 'fake-other',
    json_encode($third),
);
$editor->call('session/set_config_option', ['sessionId' => $thirdId, 'configId' => 'model', 'value' => 'fake-chat']);
$from = count($editor->updates);
$editor->call('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'Which model are you?']]]);
check('each session keeps its own', $editor->text('agent_message_chunk', $from) === 'Model: fake-other', $editor->text('agent_message_chunk', $from));
$from = count($editor->updates);
$editor->call('session/prompt', ['sessionId' => $thirdId, 'prompt' => [['type' => 'text', 'text' => 'Which model are you?']]]);
check('and switches back with it', $editor->text('agent_message_chunk', $from) === 'Model: fake-chat', $editor->text('agent_message_chunk', $from));

$from = count($editor->updates);
$set = $editor->call('session/set_mode', ['sessionId' => $thirdId, 'modeId' => 'plan']);
$synced = $editor->of('config_option_update', $from)[0]['params']['update']['configOptions'] ?? [];
check('plan mode chosen as a mode: the options follow', array_key_exists('result', $set)
    && (array_column($synced, null, 'id')['mode']['currentValue'] ?? '') === 'plan', json_encode([$set, $synced]));
check('an unknown mode is refused', ($editor->call('session/set_mode', ['sessionId' => $thirdId, 'modeId' => 'yolo'])['error']['code'] ?? 0) === -32602);

$asked = count($editor->asked);
$from = count($editor->updates);
$invoice = (string) file_get_contents("{$project}/Invoice.php");
$editor->call('session/prompt', ['sessionId' => $thirdId, 'prompt' => [['type' => 'text', 'text' => 'Patch the invoice']]]);
check('in plan mode, an edit is not even asked about', count($editor->asked) === $asked, json_encode(array_slice($editor->asked, $asked)));
check('the model is told to plan instead', str_starts_with($editor->text('agent_message_chunk', $from), 'Done: Plan mode'), $editor->text('agent_message_chunk', $from));
check('and the file is unchanged', file_get_contents("{$project}/Invoice.php") === $invoice);

$editor->answer = static fn(array $question): string => 'plan-go';
$from = count($editor->updates);
$editor->call('session/prompt', ['sessionId' => $thirdId, 'prompt' => [['type' => 'text', 'text' => 'Propose something']]]);
$modeNow = $editor->of('current_mode_update', $from)[0]['params']['update']['currentModeId'] ?? '';
check('a plan approved ends plan mode, and the editor\'s menu says so', $modeNow === 'default', json_encode($editor->of('current_mode_update', $from)));
$editor->answer = static fn(array $question): string => 'allow-once';

$from = count($editor->updates);
$set = $editor->call('session/set_config_option', ['sessionId' => $thirdId, 'configId' => 'mode', 'value' => 'plan']);
check(
    'plan mode chosen as an option: the mode follows',
    ($editor->of('current_mode_update', $from)[0]['params']['update']['currentModeId'] ?? '') === 'plan'
    && (array_column($set['result']['configOptions'] ?? [], null, 'id')['mode']['currentValue'] ?? '') === 'plan',
    json_encode($set),
);
check('another session stays as it was', (array_column(
    $editor->call('session/set_config_option', ['sessionId' => $session, 'configId' => 'model', 'value' => 'fake-other'])['result']['configOptions'] ?? [],
    null,
    'id',
)['mode']['currentValue'] ?? '') === 'default');

// ---- "/" in the editor -----------------------------------------------------------
$offered = array_column($editor->of('available_commands_update')[0]['params']['update']['availableCommands'] ?? [], null, 'name');
check('the commands are offered under "/", named without it', isset($offered['help'], $offered['compact'], $offered['memory'], $offered['plan'], $offered['auto']), json_encode(array_keys($offered)));
check('with the project\'s skills', str_starts_with((string) ($offered['greet']['description'] ?? ''), 'skill · Greet the user'), json_encode($offered['greet'] ?? null));
check('but none that needs the terminal\'s keyboard', !isset($offered['model']) && !isset($offered['reset']) && !isset($offered['provider']));
check('a command that takes an argument says which', ($offered['remember']['input']['hint'] ?? '') === '<key> <value>', json_encode($offered['remember'] ?? null));

$command = static function (string $typed) use ($editor, $session): array {
    $from = count($editor->updates);
    $reply = $editor->call('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => $typed]]]);

    return [$reply['result']['stopReason'] ?? json_encode($reply), $editor->text('agent_message_chunk', $from), $from];
};
[$stop, $said] = $command('/remember deploy-target staging');
check('a command runs in the editor and its output is the reply', $stop === 'end_turn' && str_contains($said, 'Remembered: deploy-target'), $said);
[, $said] = $command('/memory');
check('as the terminal prints it, in a block', str_starts_with($said, '```') && str_contains($said, 'deploy-target: staging'), $said);
[, $said] = $command('/model');
check('a command of the menus says where the menu is', str_contains($said, 'menu beside the prompt box'), $said);
[, $said, $from] = $command('/plan');
check('/plan turns plan mode on, and the menu follows', str_starts_with($said, 'Plan mode on')
    && ($editor->of('current_mode_update', $from)[0]['params']['update']['currentModeId'] ?? '') === 'plan', $said);
[, $said, $from] = $command('/plan off');
check('/plan off turns it off', ($editor->of('current_mode_update', $from)[0]['params']['update']['currentModeId'] ?? '') === 'default', $said);
[, $said, $from] = $command('/auto');
check('/auto turns auto mode on, and the menu follows', str_starts_with($said, 'Auto mode on')
    && ($editor->of('current_mode_update', $from)[0]['params']['update']['currentModeId'] ?? '') === 'auto', $said);
check('and says what is still asked', str_contains($said, 'Still asked every time'), $said);
$asked = count($editor->asked);
$invoice = (string) file_get_contents("{$project}/Invoice.php");
$command('Patch the invoice again');
check('in auto mode, an edit in the project is made without asking', count($editor->asked) === $asked
    && str_contains((string) file_get_contents("{$project}/Invoice.php"), 'return 43;'), json_encode(array_slice($editor->asked, $asked)));
file_put_contents("{$project}/Invoice.php", $invoice);
[, $said, $from] = $command('/auto off');
check('/auto off turns it off', ($editor->of('current_mode_update', $from)[0]['params']['update']['currentModeId'] ?? '') === 'default', $said);
$command('Patch the invoice again');
check('and the same edit is asked about again', count($editor->asked) === $asked + 1, json_encode(array_slice($editor->asked, $asked)));
file_put_contents("{$project}/Invoice.php", $invoice);
[, $said] = $command('/greet the new colleague');
check('a skill run as a command goes to the model with its text', $said === 'Skill applied: greet', $said);
[$stop, $said] = $command('/etc/hosts is wrong here');
check('a slash that names nothing is a message like any other', $stop === 'end_turn' && $said === 'Hello from the fake model.', $said);

// ---- cancelled mid-reply ----------------------------------------------------------
$from = count($editor->updates);
$cancelled = false;
$started = microtime(true);
$reply = $editor->call(
    'session/prompt',
    ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'Be slow']]],
    20.0,
    function (array $message) use ($editor, $session, &$cancelled): void {
        if (!$cancelled && ($message['params']['update']['sessionUpdate'] ?? '') === 'agent_message_chunk') {
            $cancelled = true;
            $editor->notify('session/cancel', ['sessionId' => $session]);
        }
    },
);
check('a cancel ends the turn as cancelled, not as an error', ($reply['result']['stopReason'] ?? '') === 'cancelled', json_encode($reply));
check('and soon', microtime(true) - $started < 5.0, sprintf('%.1f s', microtime(true) - $started));

$from = count($editor->updates);
$reply = $editor->call('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'Say hello']]]);
check('said once', !str_contains($editor->text('agent_thought_chunk', $from), 'New project'));
check('the session goes on after a cancel', ($reply['result']['stopReason'] ?? '') === 'end_turn', json_encode($reply));

// ---- "for this session" is this session's ----------------------------------------------
file_put_contents("{$project}/Invoice.php", "<?php\nfunction subtotal(): int\n{\n    return 0;\n}\n");
$editor->answer = static fn(array $question): string => 'allow-session';
$asked = count($editor->asked);
$editor->call('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'Patch the invoice']]]);
$other = (string) ($editor->call('session/new', ['cwd' => $project, 'mcpServers' => []])['result']['sessionId'] ?? '');
$editor->call('session/prompt', ['sessionId' => $other, 'prompt' => [['type' => 'text', 'text' => 'Patch it again']]]);
check('a tool allowed for one session is asked about again in another', count($editor->asked) === $asked + 2, (string) (count($editor->asked) - $asked));
file_put_contents("{$project}/Invoice.php", "<?php\nfunction subtotal(): int\n{\n    return 0;\n}\n");
$editor->call('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'Patch the invoice']]]);
check('and not again in the session it was allowed for', count($editor->asked) === $asked + 2, (string) (count($editor->asked) - $asked));
check('which still edits', str_contains((string) file_get_contents("{$project}/Invoice.php"), 'return 42;'));

// ---- what is not right ------------------------------------------------------------
check('an unknown method is said to be unknown', ($editor->call('session/teleport', [])['error']['code'] ?? 0) === -32601);
check(
    'a prompt to a session that does not exist is refused',
    ($editor->call('session/prompt', ['sessionId' => 'nope', 'prompt' => [['type' => 'text', 'text' => 'x']]])['error']['code'] ?? 0) === -32002,
);

check('nothing but JSON-RPC messages on stdout', $editor->stray === [], implode("\n", $editor->stray));
// ---- the conversations kept, listed -----------------------------------------------------
check('Sherpa says it lists its conversations', isset($init['result']['agentCapabilities']['sessionCapabilities']['list']), json_encode($init['result']['agentCapabilities'] ?? null));
$listed = array_column($editor->call('session/list', ['cwd' => $project])['result']['sessions'] ?? [], null, 'sessionId');
check('the project\'s conversations are listed, named, in the project', ($listed[$session]['title'] ?? '') === 'Say hello'
    && ($listed[$session]['cwd'] ?? '') === $project && isset($listed[$other]), json_encode($listed));
check('with when they last moved', strtotime((string) ($listed[$session]['updatedAt'] ?? '')) > time() - 600);
check('every project\'s, with no directory given', isset(array_column($editor->call('session/list', [])['result']['sessions'] ?? [], null, 'sessionId')[$session]));
check('none for a directory Sherpa does not know', ($editor->call('session/list', ['cwd' => "{$work}/blank"])['result']['sessions'] ?? null) === []);

$editor->close();

// ---- loaded back in a new process --------------------------------------------------
$again = new Editor($work, $api);
$again->call('initialize', ['protocolVersion' => 1], 60.0);
$loaded = $again->call('session/load', ['sessionId' => $session, 'cwd' => $project, 'mcpServers' => []]);
check('session/load picks the conversation back up', array_key_exists('result', $loaded), json_encode($loaded));
check('with its mode and model to show', ($loaded['result']['modes']['currentModeId'] ?? '') === 'default'
    && array_column($loaded['result']['configOptions'] ?? [], 'id') === ['mode', 'model'], json_encode($loaded));
check('replaying what was asked', str_contains($again->text('user_message_chunk'), 'Say hello') && str_contains($again->text('user_message_chunk'), 'Patch'), $again->text('user_message_chunk'));
check('and what was answered', str_contains($again->text('agent_message_chunk'), 'Hello from the fake model.'));
check('and the tools in between', count($again->of('tool_call')) >= 1 && count($again->of('tool_call_update')) >= 1);
$reply = $again->call('session/prompt', ['sessionId' => $session, 'prompt' => [['type' => 'text', 'text' => 'Say hello']]]);
check('then goes on', ($reply['result']['stopReason'] ?? '') === 'end_turn', json_encode($reply));
check('an unknown conversation cannot be loaded', ($again->call('session/load', ['sessionId' => '20990101-000000-abcd', 'cwd' => $project, 'mcpServers' => []])['error']['code'] ?? 0) === -32002);
$again->close();

// ---- an editor that serves the files ------------------------------------------------
// Invoice.php is open in a tab with a change not yet saved: the model must
// patch what the user sees, and the result go back to the tab, not the disk.
file_put_contents("{$project}/Invoice.php", "<?php\nfunction subtotal(): int\n{\n    return 0;\n}\n");
$tabs = new Editor($work, $api);
$tabs->buffers["{$project}/Invoice.php"] = "<?php\n// not saved yet\nfunction subtotal(): int\n{\n    return 0;\n}\n";
$tabs->call('initialize', ['protocolVersion' => 1, 'clientCapabilities' => ['fs' => ['readTextFile' => true, 'writeTextFile' => true]]], 60.0);
$tabSession = (string) ($tabs->call('session/new', ['cwd' => $project, 'mcpServers' => []])['result']['sessionId'] ?? '');
$reply = $tabs->call('session/prompt', ['sessionId' => $tabSession, 'prompt' => [['type' => 'text', 'text' => 'Patch the invoice']]]);
$asked = $tabs->asked[0]['toolCall']['content'][0] ?? [];
check('the diff asked about is against the tab, unsaved change included', str_contains((string) ($asked['oldText'] ?? ''), '// not saved yet'), json_encode($asked));
check('the file is read through the editor', in_array("{$project}/Invoice.php", $tabs->read, true));
$written = $tabs->written["{$project}/Invoice.php"] ?? '';
check('and written back through it, the unsaved change kept', str_contains($written, '// not saved yet') && str_contains($written, 'return 42;'), $written);
check('the disk is left to the editor', !str_contains((string) file_get_contents("{$project}/Invoice.php"), 'return 42;'));
check('the turn ends as usual', ($reply['result']['stopReason'] ?? '') === 'end_turn', json_encode($reply));

$tabs->buffers = [];
$tabs->refuseReads = true;
$from = count($tabs->updates);
$tabs->call('session/prompt', ['sessionId' => $tabSession, 'prompt' => [['type' => 'text', 'text' => 'Patch the invoice']]]);
check('a file the editor cannot give is read from disk', str_starts_with($tabs->text('agent_message_chunk', $from), 'Done: Patched'), $tabs->text('agent_message_chunk', $from));
$tabs->close();

// ---- what a conversation taught, kept ----------------------------------------------
// Opening another conversation is when the last one's facts are taken: an
// editor never says it is over.
mkdir("{$work}/memory-home", 0777, true);
$memory = new Editor($work, $api, 'memory-home', facts: true);
$memory->call('initialize', ['protocolVersion' => 1], 60.0);
$first = (string) ($memory->call('session/new', ['cwd' => $project, 'mcpServers' => []])['result']['sessionId'] ?? '');
$memory->call('session/prompt', ['sessionId' => $first, 'prompt' => [['type' => 'text', 'text' => 'Say hello']]]);
$memory->call('session/prompt', ['sessionId' => $first, 'prompt' => [['type' => 'text', 'text' => 'Say hello again']]]);
$second = (string) ($memory->call('session/new', ['cwd' => $project, 'mcpServers' => []])['result']['sessionId'] ?? '');
$from = count($memory->updates);
$memory->call('session/prompt', ['sessionId' => $second, 'prompt' => [['type' => 'text', 'text' => '/memory']]]);
check(
    'the facts of a conversation are kept once another opens',
    str_contains($memory->text('agent_message_chunk', $from), 'test-command: php tests/run.php'),
    $memory->text('agent_message_chunk', $from),
);

$slow = (string) ($memory->call('session/new', ['cwd' => $project, 'mcpServers' => []])['result']['sessionId'] ?? '');
$memory->call('session/prompt', ['sessionId' => $slow, 'prompt' => [['type' => 'text', 'text' => 'Say hello, take your time']]]);
$memory->call('session/prompt', ['sessionId' => $slow, 'prompt' => [['type' => 'text', 'text' => 'Say hello']]]);
$next = (string) ($memory->call('session/new', ['cwd' => $project, 'mcpServers' => []])['result']['sessionId'] ?? '');
$started = microtime(true);
$from = count($memory->updates);
$reply = $memory->call('session/prompt', ['sessionId' => $next, 'prompt' => [['type' => 'text', 'text' => 'Say hello']]]);
check('a prompt sent while facts are taken does not wait for them', microtime(true) - $started < 4.0
    && $memory->text('agent_message_chunk', $from) === 'Hello from the fake model.', sprintf('%.1f s, %s', microtime(true) - $started, json_encode($reply)));
$memory->close();

// ---- no model -------------------------------------------------------------------
$dead = new Editor($work, 'http://127.0.0.1:9/v1');
check('without a model, initialize still answers', ($dead->call('initialize', ['protocolVersion' => 1], 60.0)['result']['protocolVersion'] ?? null) === 1);
$error = (string) ($dead->call('session/new', ['cwd' => $project, 'mcpServers' => []])['error']['message'] ?? '');
check('and opening a session says why, the way the terminal does', str_contains($error, 'does not answer'), $error);
check('as an error, not as a setup to offer: it was set up', ($dead->call('session/new', ['cwd' => $project, 'mcpServers' => []])['error']['code'] ?? 0) === -32603);
check('still nothing but messages on stdout', $dead->stray === [], implode("\n", $dead->stray));
$dead->close();

// ---- nothing set up: the editor offers to ------------------------------------------
mkdir("{$work}/blank", 0777, true);
$blank = new Editor($work, null, 'blank');
$init = $blank->call('initialize', ['protocolVersion' => 1, 'clientCapabilities' => ['auth' => ['terminal' => true]]], 60.0);
$methods = $init['result']['authMethods'] ?? [];
check(
    'an editor that can run a terminal is offered the setup as terminal authentication',
    count($methods) === 1 && $methods[0]['type'] === 'terminal' && $methods[0]['args'] === ['--setup'] && $methods[0]['id'] === 'setup',
    json_encode($methods),
);
$blank->close();

// The form the registry's validator and earlier editors send.
$blank = new Editor($work, null, 'blank');
$methods = $blank->call('initialize', ['protocolVersion' => 1, 'clientCapabilities' => ['_meta' => ['terminal-auth' => true]]], 60.0)['result']['authMethods'] ?? [];
$meta = $methods[0]['_meta']['terminal-auth'] ?? [];
check(
    'an editor asking the earlier way is offered it too, with the whole command',
    ($methods[0]['type'] ?? '') === 'terminal' && $meta['command'] === PHP_BINARY
    && $meta['args'] === [realpath(__DIR__ . '/../bin/sherpa'), 'acp', '--setup'],
    json_encode($methods),
);
$refused = $blank->call('session/new', ['cwd' => $project, 'mcpServers' => []]);
check('and a session asks for it: auth_required', ($refused['error']['code'] ?? 0) === -32000, json_encode($refused));
check('saying what is missing', str_contains((string) ($refused['error']['message'] ?? ''), 'No API configured'), json_encode($refused));
$blank->close();

$plain = new Editor($work, null, 'blank');
check(
    'an editor that cannot run a terminal is offered nothing to run',
    ($plain->call('initialize', ['protocolVersion' => 1], 60.0)['result']['authMethods'] ?? null) === [],
);
$plain->close();

exec(sprintf('%s %s acp --setup < /dev/null 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/../bin/sherpa')), $said, $code);
check('the setup outside a terminal says it needs one, and fails', $code !== 0 && str_contains(implode("\n", $said), 'run it in a terminal'), implode("\n", $said));

proc_terminate($server);
proc_close($server);
exec('rm -rf ' . escapeshellarg($work));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
