<?php

declare(strict_types=1);

namespace App\Tests\AcpFrontendTest;

use App\Acp\SessionConfig;
use App\Permission\Offer;

// The pieces of `sherpa acp` that turn Sherpa's turn into what an editor
// draws, and an editor's prompt into what the model reads.

require __DIR__ . '/../vendor/autoload.php';

use App\Acp\Connection;
use App\Acp\EditorFrontend;
use App\Acp\PromptText;
use App\Acp\ToolDescriptions;
use App\Agent\TodoList;
use App\Agent\Tool\Permission;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;
use App\Permission\ConfirmChoice;
use App\Project\ProjectPathResolver;
use App\Tool\Edit\EditPreview;
use App\Tool\FilePatchTool;
use App\Tool\FileWriteTool;
use App\Tool\ShellExecTool;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo "        ", str_replace("\n", "\n        ", substr($detail, 0, 500)), "\n";
    }
}

// ---- the prompt ----------------------------------------------------------------------
$text = PromptText::from([
    ['type' => 'text', 'text' => 'Why does'],
    ['type' => 'resource_link', 'uri' => 'file:///work/shop/src/Billing/Invoice.php', 'name' => 'Invoice.php'],
    ['type' => 'text', 'text' => 'return 0?'],
], '/work/shop');
check('a file mentioned becomes its path in the project, in its place', $text === 'Why does @src/Billing/Invoice.php return 0?', $text);

check(
    'a file outside the project keeps its full path',
    PromptText::from([['type' => 'resource_link', 'uri' => 'file:///etc/hosts', 'name' => 'hosts']], '/work/shop') === '@/etc/hosts ',
);
check(
    'a link that is no file keeps its name',
    PromptText::from([['type' => 'resource_link', 'uri' => 'https://x.test/a', 'name' => 'the spec']], '/work/shop') === '@the spec ',
);
check(
    'a path with spaces is decoded',
    str_contains(PromptText::from([['type' => 'resource_link', 'uri' => 'file:///work/shop/My%20File.php', 'name' => 'x']], '/work/shop'), '@My File.php'),
);

$embedded = PromptText::from([
    ['type' => 'text', 'text' => 'Explain this'],
    ['type' => 'resource', 'resource' => ['uri' => 'file:///work/shop/a.php', 'text' => "<?php\necho 1;\n"]],
], '/work/shop');
check('a file sent whole is given whole, after the request', $embedded === "Explain this\n\n<file path=\"a.php\">\n<?php\necho 1;\n</file>", $embedded);
$png = base64_encode((string) hex2bin('89504e470d0a1a0a'));
$look = [['type' => 'text', 'text' => 'Look'], ['type' => 'image', 'mimeType' => 'image/png', 'data' => $png]];
check('an image goes with the text to a model that sees', PromptText::from($look, '/w', seesImages: true) === 'Look'
    && PromptText::images($look) === [['mimeType' => 'image/png', 'data' => $png]]);
check(
    'to one that does not, it is said to be there rather than dropped',
    str_contains(PromptText::from($look, '/w'), 'an image was attached, which this model cannot see'),
);
$tiff = [['type' => 'image', 'mimeType' => 'image/tiff', 'data' => $png]];
check('a type providers refuse is not sent, and said', PromptText::images($tiff) === [] && str_contains(PromptText::from($tiff, '/w', true), 'image/tiff'));
$huge = [['type' => 'image', 'mimeType' => 'image/png', 'data' => base64_encode(str_repeat('x', 6 * 1024 * 1024))]];
check('nor one past 5 MB', PromptText::images($huge) === [] && str_contains(PromptText::from($huge, '/w', true), '6.0 MB'));

// ---- titles and kinds -------------------------------------------------------------------
check('a command is titled by itself', ToolDescriptions::title('shell_exec', ['command' => 'make test']) === 'make test');
check('a read says where from', ToolDescriptions::title('file_read', ['path' => 'a.php', 'offset' => 40]) === 'Read a.php from line 40');
check('a search says what and where', ToolDescriptions::title('project_grep', ['pattern' => 'subtotal', 'path' => 'src']) === 'Search for subtotal in src');
check('a title is one line, and short', ToolDescriptions::title('shell_exec', ['command' => "a\nb" . str_repeat('x', 200)]) === mb_strimwidth("a b" . str_repeat('x', 200), 0, 80, '…'));
check('an unknown tool is titled by its name', ToolDescriptions::title('github__create_issue', ['title' => 'x']) === 'github__create_issue');
check(
    'kinds pick the editor\'s icons',
    [ToolDescriptions::kind('file_patch'), ToolDescriptions::kind('shell_exec'), ToolDescriptions::kind('project_grep'), ToolDescriptions::kind('file_read'), ToolDescriptions::kind('github__x')]
    === ['edit', 'execute', 'search', 'read', 'other'],
);

// ---- the frontend --------------------------------------------------------------------
$project = sys_get_temp_dir() . '/sherpa-acpfront-' . bin2hex(random_bytes(4));
mkdir($project, 0777, true);
file_put_contents("{$project}/Invoice.php", "<?php\nreturn 0;\n");
$paths = new ProjectPathResolver();
$paths->setRoot($project);
$todos = new TodoList();

/** A connection to nobody but a buffer this test reads, which answers questions as told. */
function frontend(ProjectPathResolver $paths, TodoList $todos, array $answers = []): array
{
    [$sherpa, $editor] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    foreach ($answers as $i => $answer) {
        fwrite($editor, json_encode(['jsonrpc' => '2.0', 'id' => $i + 1, 'result' => $answer]) . "\n");
    }
    $front = new EditorFrontend(new Connection($sherpa, $sherpa), new EditPreview(new FileWriteTool($paths), new FilePatchTool($paths)), $paths, new ShellExecTool($paths), $todos);
    $front->forSession('s1', false);

    return [$front, $editor];
}

function out($editor): array
{
    stream_set_blocking($editor, false);
    $raw = '';
    while (($chunk = fread($editor, 65536)) !== false && $chunk !== '') {
        $raw .= $chunk;
    }

    return array_map(fn(string $l): mixed => json_decode($l, true), array_values(array_filter(explode("\n", $raw))));
}

function updates(array $messages): array
{
    return array_values(array_map(fn(array $m) => $m['params']['update'], array_filter($messages, fn(array $m): bool => ($m['method'] ?? '') === 'session/update')));
}

// A reply that turns out to be a tool call written as prose is never sent.
[$front, $editor] = frontend($paths, $todos);
$front->beginAssistantMessage();
foreach (['{"name": "file_read", ', '"arguments": {"path": "a.php"}}'] as $token) {
    $front->appendToken($token);
}
$front->flushBuffer(wasToolCall: true);
check('a tool call written as prose is held back, and dropped once recovered', updates(out($editor)) === []);

$front->beginAssistantMessage();
foreach (["\n\n", 'Hello', ' there'] as $token) {
    $front->appendToken($token);
}
$front->flushBuffer();
$sent = updates(out($editor));
check(
    'prose streams, without the blank lines some models open with',
    array_column(array_column($sent, 'content'), 'text') === ['Hello', ' there'] && array_unique(array_column($sent, 'messageId')) === [$sent[0]['messageId']],
    json_encode($sent),
);

$front->beginAssistantMessage();
$front->appendToken('{ "not": "a call" }');
$front->flushBuffer(wasToolCall: false);
check('held text that was no tool call is sent at the end', (updates(out($editor))[0]['content']['text'] ?? '') === '{ "not": "a call" }');

// Waits: said once, not at every tick of a countdown.
$front->beginAssistantMessage();
$front->showWaiting(false, 'Rate limited, trying again in 30 s…');
$front->showWaiting(false, 'Rate limited, trying again in 29 s…');
$front->showWaiting(true);
$sent = updates(out($editor));
check('a rate-limit wait is said once, as a thought', count($sent) === 1 && $sent[0]['sessionUpdate'] === 'agent_thought_chunk', json_encode($sent));

// Notices, where the editor shows them.
$front->forSession('s1', true);
$front->showInfo("Context compacted: \e[31m~9000\e[0m → ~4000 tokens.");
$sent = updates(out($editor));
check(
    'a remark is a notice where the editor takes them, without escape codes',
    ($sent[0]['sessionUpdate'] ?? '') === 'notice' && $sent[0]['title'] === 'Context compacted: ~9000 → ~4000 tokens.',
    json_encode($sent),
);
$front->forSession('s1', false);

$front->showError('The API refused the key.');
$sent = updates(out($editor));
check('an error is part of the conversation', ($sent[0]['sessionUpdate'] ?? '') === 'agent_message_chunk' && str_contains($sent[0]['content']['text'], 'The API refused the key.'));

// A command: its card, its preview, its result as a block.
$front->showToolCall('shell_exec', ['command' => 'make test'], 'c1');
$front->showToolResult('shell_exec', "(exit code 2)\nFAILED tests/InvoiceTest.php", false, 'c1');
$sent = updates(out($editor));
check('a command is shown before it runs, as it will run', ($sent[0]['kind'] ?? '') === 'execute' && str_contains($sent[0]['content'][0]['content']['text'] ?? '', '$ make test'), json_encode($sent[0] ?? null));
check(
    'a command that failed is failed, its output in a code block',
    ($sent[1]['status'] ?? '') === 'failed' && str_starts_with($sent[1]['content'][0]['content']['text'] ?? '', "```\n(exit code 2)"),
    json_encode($sent[1] ?? null),
);

$front->showToolCall('shell_exec', ['command' => 'cat notes.md'], 'c2');
$front->showToolResult('shell_exec', "```php\necho 1;\n```", false, 'c2');
$sent = updates(out($editor));
check('output holding a fence gets a longer one', str_starts_with($sent[1]['content'][0]['content']['text'] ?? '', "````\n```php"), json_encode($sent[1] ?? null));

// A call refused before it was ever shown still gets its card.
$front->showToolResult('file_write', 'arguments cut off: not run', true, 'c3');
$sent = updates(out($editor));
check('a call cut off before it was shown gets a failed card', ($sent[0]['sessionUpdate'] ?? '') === 'tool_call' && $sent[0]['status'] === 'failed', json_encode($sent));

// The plan.
$todos->replace("[x] Read\n[>] Fix\n[ ] Test");
$front->showToolCall('todo_write', ['tasks' => '…'], 'c4');
$front->showToolResult('todo_write', 'Plan (1/3 done)', false, 'c4');
$sent = updates(out($editor));
check('the plan is a plan update, no card', count($sent) === 1 && $sent[0]['sessionUpdate'] === 'plan' && array_column($sent[0]['entries'], 'content') === ['Read', 'Fix', 'Test'], json_encode($sent));

// ---- questions --------------------------------------------------------------------
$def = new ToolDefinition('file_patch', 'Patch a file', [], Permission::CONFIRM, new ShellExecTool());
$call = new ToolCall('p1', 'file_patch', ['path' => 'Invoice.php', 'search' => 'return 0;', 'replace' => 'return 1;']);
$answers = [
    ['outcome' => ['outcome' => 'selected', 'optionId' => 'allow-session']],
    ['outcome' => ['outcome' => 'cancelled']],
    ['outcome' => ['outcome' => 'selected', 'optionId' => 'something-else']],
    ['outcome' => ['outcome' => 'selected', 'optionId' => 'plan-go']],
    ['outcome' => ['outcome' => 'selected', 'optionId' => 'plan-keep']],
];
[$front, $editor] = frontend($paths, $todos, $answers);
$front->showToolCall('file_patch', $call->arguments, 'p1');
check('"for this session" is the session grant', $front->confirm($call, $def, new Offer()) === ConfirmChoice::Session);
$asked = array_values(array_filter(out($editor), fn(array $m): bool => ($m['method'] ?? '') === 'session/request_permission'))[0]['params'] ?? [];
check('the question names the session and the call, with the diff', $asked['sessionId'] === 's1' && $asked['toolCall']['toolCallId'] === 'p1'
    && $asked['toolCall']['content'][0]['type'] === 'diff' && str_contains($asked['toolCall']['content'][0]['newText'], 'return 1;'), json_encode($asked));
check('a cancelled question is a refusal', $front->confirm($call, $def, new Offer()) === ConfirmChoice::Deny);
check('so is an answer naming no option offered', $front->confirm($call, $def, new Offer()) === ConfirmChoice::Deny);

$front->showToolCall('plan_ready', ['plan' => '1. Fix'], 'r1');
check('a plan carried out on "go"', $front->approvePlan('1. Fix') === true);
check('and kept on "keep planning"', $front->approvePlan('1. Fix') === false);
$planned = array_values(array_filter(out($editor), fn(array $m): bool => ($m['method'] ?? '') === 'session/request_permission' && $m['params']['options'][0]['optionId'] === 'plan-go'));
check('asked under the plan_ready call', ($planned[0]['params']['toolCall']['toolCallId'] ?? '') === 'r1' && str_contains(json_encode($planned[0]), '1. Fix'), json_encode($planned[0] ?? null));

// A command: the longer answers name what they grant, and what is asked
// every time is offered once or refused, nothing else.
$shellDef = new ToolDefinition('shell_exec', 'Run a command', [], Permission::CONFIRM, new ShellExecTool());
$shellCall = new ToolCall('x1', 'shell_exec', ['command' => 'make test']);
[$front, $editor] = frontend($paths, $todos, [
    ['outcome' => ['outcome' => 'selected', 'optionId' => 'allow-project']],
    ['outcome' => ['outcome' => 'selected', 'optionId' => 'allow-session']],
]);
$front->showToolCall('shell_exec', $shellCall->arguments, 'x1');
check('a command granted for the project', $front->confirm($shellCall, $shellDef, new Offer(['shell_exec: make test *'])) === ConfirmChoice::Project);
$front->showToolCall('shell_exec', ['command' => 'git push'], 'x2');
check(
    '"for this session" is no answer where only once is offered',
    $front->confirm(new ToolCall('x2', 'shell_exec', ['command' => 'git push']), $shellDef, new Offer([], 'it pushes to a remote')) === ConfirmChoice::Deny,
);
$asked = array_values(array_map(fn(array $m) => $m['params'], array_filter(out($editor), fn(array $m): bool => ($m['method'] ?? '') === 'session/request_permission')));
check('the grant offered names the command', str_contains(json_encode($asked[0]['options'] ?? []), 'Always allow make test * in this project'), json_encode($asked[0]['options'] ?? []));
check('only once or refuse for what is asked every time', array_column($asked[1]['options'] ?? [], 'optionId') === ['allow-once', 'reject'], json_encode($asked[1]['options'] ?? []));
check('with the reason', str_contains(json_encode($asked[1]['toolCall']['content'] ?? []), 'Asked every time: it pushes to a remote'));

// An editor that went away answers nothing, which allows nothing.
[$sherpa, $gone] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
fclose($gone);
$orphan = new EditorFrontend(new Connection($sherpa, $sherpa), new EditPreview(new FileWriteTool($paths), new FilePatchTool($paths)), $paths, new ShellExecTool($paths), $todos);
check('no editor to answer: refused', $orphan->confirm($call, $def, new Offer()) === ConfirmChoice::Deny);
check('and no plan approved', $orphan->approvePlan('x') === null);

// ---- the model menu ------------------------------------------------------------------
$menu = SessionConfig::grouped([
    ['value' => 'meta/llama-3.3-70b-instruct', 'name' => 'meta/llama-3.3-70b-instruct'],
    ['value' => 'qwen/qwen3-coder', 'name' => 'qwen/qwen3-coder', 'description' => '256k context'],
    ['value' => 'meta/llama-3.1-8b-instruct', 'name' => 'meta/llama-3.1-8b-instruct'],
]);
check('a provider\'s models are grouped under their vendor', array_column($menu, 'name') === ['meta', 'qwen'], json_encode($menu));
check('each shown without it, chosen by its whole id', ($menu[0]['options'][1] ?? []) === ['name' => 'llama-3.1-8b-instruct', 'value' => 'meta/llama-3.1-8b-instruct'], json_encode($menu[0]));
check('its details kept', ($menu[1]['options'][0]['description'] ?? '') === '256k context');
$flat = [['value' => 'fake-chat', 'name' => 'fake-chat'], ['value' => 'acme/x', 'name' => 'acme/x']];
check('a list with ids that name no vendor stays flat', SessionConfig::grouped($flat) === $flat);

exec('rm -rf ' . escapeshellarg($project));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
