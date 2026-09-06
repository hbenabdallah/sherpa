<?php
// Anthropic's Messages API, spoken natively: the request shape, the event
// stream, prompt caching and thinking blocks. Nothing reaches a network.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Platform\AnthropicWire;
use App\Platform\OpenAiCompatiblePlatform;
use App\Platform\UnknownEmbeddingModel;
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
        echo '        ', substr($detail, 0, 400), "\n";
    }
}

/** One event as Anthropic writes it: an event line, then its data. */
function ev(array $data): string
{
    return 'event: ' . $data['type'] . "\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
}

/**
 * A reply: optional thinking, optional text, optional tool call, with usage.
 *
 * @param array{thinking?: string, signature?: string, text?: string, tool?: array{id: string, name: string, input: array<string, mixed>}, stop?: string, usage?: array<string, int>} $reply
 */
function reply(array $reply): MockResponse
{
    $usage = ($reply['usage'] ?? []) + ['input_tokens' => 50, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0, 'output_tokens' => 1];
    $events = [ev(['type' => 'message_start', 'message' => ['id' => 'msg_1', 'role' => 'assistant', 'content' => [], 'usage' => $usage]])];
    $i = 0;

    if (isset($reply['thinking'])) {
        $events[] = ev(['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']]);
        $events[] = ev(['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'thinking_delta', 'thinking' => $reply['thinking']]]);
        $events[] = ev(['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'signature_delta', 'signature' => $reply['signature'] ?? 'sig']]);
        $events[] = ev(['type' => 'content_block_stop', 'index' => $i++]);
    }
    if (isset($reply['text'])) {
        $events[] = ev(['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'text', 'text' => '']]);
        foreach (str_split($reply['text'], 4) as $piece) {
            $events[] = ev(['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'text_delta', 'text' => $piece]]);
        }
        $events[] = ev(['type' => 'content_block_stop', 'index' => $i++]);
    }
    if (isset($reply['tool'])) {
        $json = json_encode($reply['tool']['input']);
        $events[] = ev(['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'tool_use', 'id' => $reply['tool']['id'], 'name' => $reply['tool']['name'], 'input' => new stdClass()]]);
        foreach (str_split($json, 5) as $piece) {
            $events[] = ev(['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $piece]]);
        }
        $events[] = ev(['type' => 'content_block_stop', 'index' => $i++]);
    }

    $stop = $reply['stop'] ?? (isset($reply['tool']) ? 'tool_use' : 'end_turn');
    $events[] = ev(['type' => 'message_delta', 'delta' => ['stop_reason' => $stop], 'usage' => ['output_tokens' => 42]]);
    $events[] = ev(['type' => 'message_stop']);

    return new MockResponse($events, ['response_headers' => ['content-type' => 'text/event-stream']]);
}

/**
 * @param array<int, MockResponse> $responses
 */
function anthropic(array $responses, array &$sent = []): OpenAiCompatiblePlatform
{
    $client = new MockHttpClient(function ($method, $url, $options) use (&$responses, &$sent) {
        $headers = [];
        foreach ($options['headers'] ?? [] as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            $headers[strtolower($name)] = $value;
        }
        $sent[] = ['method' => $method, 'url' => $url, 'body' => json_decode($options['body'] ?? '{}', true), 'raw' => (string) ($options['body'] ?? ''), 'headers' => $headers];

        return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
    });

    return new OpenAiCompatiblePlatform($client, 'https://api.anthropic.com/v1', 'claude-opus-5', 'sk-ant-test', 200000, 30.0);
}

$tools = [['type' => 'function', 'function' => ['name' => 'file_read', 'description' => 'Read a file', 'parameters' => [
    'type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path'],
]]], ['type' => 'function', 'function' => ['name' => 'list_dir', 'description' => 'List', 'parameters' => ['type' => 'object', 'properties' => []]]]];

// ---- which dialect ---------------------------------------------------------

check('Anthropic is recognised by its address', AnthropicWire::handles('https://api.anthropic.com/v1'));
check('and nothing else is', !AnthropicWire::handles('https://api.openai.com/v1') && !AnthropicWire::handles('https://anthropic.com.evil.test/v1'));

// ---- one turn with a tool call ---------------------------------------------

$sent = [];
$platform = anthropic([reply([
    'thinking' => 'The user wants the README.', 'signature' => 'SIG-1',
    'text'     => 'Reading it.',
    'tool'     => ['id' => 'toolu_01', 'name' => 'file_read', 'input' => ['path' => 'README.md']],
    'usage'    => ['input_tokens' => 20, 'cache_read_input_tokens' => 3000, 'cache_creation_input_tokens' => 400],
])], $sent);

$tokens = [];
$waited = [];
$history = [
    ['role' => 'system', 'content' => 'You are Sherpa.'],
    ['role' => 'user', 'content' => 'What does the README say?'],
];
$message = $platform->stream($history, $tools, function (string $t) use (&$tokens) { $tokens[] = $t; }, function (bool $r) use (&$waited) { $waited[] = $r; });

$request = $sent[0];
check('the request goes to /v1/messages', $request['url'] === 'https://api.anthropic.com/v1/messages', $request['url']);
check('with the key as x-api-key and the API version',
    ($request['headers']['x-api-key'] ?? '') === 'sk-ant-test' && ($request['headers']['anthropic-version'] ?? '') === '2023-06-01',
    json_encode($request['headers']));
check('and no bearer token', !isset($request['headers']['authorization']), json_encode($request['headers']));
check('no temperature: current models refuse sampling parameters', !array_key_exists('temperature', $request['body']), json_encode($request['body']));
check('room for thinking in max_tokens', ($request['body']['max_tokens'] ?? 0) >= 16000, (string) ($request['body']['max_tokens'] ?? ''));
check('the system prompt is its own field, marked for the cache',
    ($request['body']['system'][0]['text'] ?? '') === 'You are Sherpa.' && ($request['body']['system'][0]['cache_control']['type'] ?? '') === 'ephemeral',
    json_encode($request['body']['system'] ?? null));
check('and a moving breakpoint caches the conversation', ($request['body']['cache_control']['type'] ?? '') === 'ephemeral');
check('the first message is the user\'s', ($request['body']['messages'][0]['role'] ?? '') === 'user' && count($request['body']['messages']) === 1,
    json_encode($request['body']['messages']));
check('tools carry an input_schema', ($request['body']['tools'][0]['input_schema']['required'] ?? null) === ['path'], json_encode($request['body']['tools'][0] ?? null));
check('and a tool without parameters an empty object, not a list',
    str_contains($request['raw'], '"name":"list_dir","description":"List","input_schema":{"type":"object","properties":{}}'), $request['raw']);

check('text streams as it comes', implode('', $tokens) === 'Reading it.' && count($tokens) > 1, json_encode($tokens));
check('thinking counts as generation, not silence', in_array(true, $waited, true), json_encode($waited));
check('the tool call is assembled from its fragments',
    ($message['tool_calls'][0]['id'] ?? '') === 'toolu_01' && ($message['tool_calls'][0]['function']['arguments'] ?? null) === ['path' => 'README.md'],
    json_encode($message));
check('the thinking block is kept, signature and all',
    ($message[AnthropicWire::THINKING][0]['signature'] ?? '') === 'SIG-1' && ($message[AnthropicWire::THINKING][0]['thinking'] ?? '') === 'The user wants the README.',
    json_encode($message));

$usage = $platform->lastUsage();
check('the prompt size counts what was cached and written', ($usage['prompt'] ?? 0) === 3420, json_encode($usage));
check('the cache read is reported', ($usage['cached'] ?? 0) === 3000, json_encode($usage));
check('output tokens come from the final count', ($usage['completion'] ?? 0) === 42, json_encode($usage));

// ---- the next turn sends it all back ---------------------------------------

$history[] = $message;
$history[] = ['role' => 'tool', 'name' => 'file_read', 'tool_call_id' => 'toolu_01', 'content' => '# Demo'];

$sent2 = [];
$platform2 = anthropic([reply(['thinking' => 'Got it.', 'signature' => 'SIG-2', 'text' => 'It says Demo.'])], $sent2);
$platform2->stream($history, $tools, fn() => null);
$turns = $sent2[0]['body']['messages'];

check('the assistant turn goes back as blocks: thinking, text, tool_use',
    array_column($turns[1]['content'] ?? [], 'type') === ['thinking', 'text', 'tool_use'], json_encode($turns[1] ?? null));
check('the thinking block unchanged', ($turns[1]['content'][0] ?? null) === ['type' => 'thinking', 'thinking' => 'The user wants the README.', 'signature' => 'SIG-1'],
    json_encode($turns[1]['content'][0] ?? null));
check('the tool result is a block in a user turn, tied to its call',
    ($turns[2]['role'] ?? '') === 'user' && ($turns[2]['content'][0]['type'] ?? '') === 'tool_result' && ($turns[2]['content'][0]['tool_use_id'] ?? '') === 'toolu_01',
    json_encode($turns[2] ?? null));

// Several results answer several calls: one user turn, results first.
$shaped = AnthropicWire::payload('m', [
    ['role' => 'user', 'content' => 'go'],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['id' => 'a', 'function' => ['name' => 'x', 'arguments' => []]],
        ['id' => 'b', 'function' => ['name' => 'y', 'arguments' => '{"k":1}']],
    ]],
    ['role' => 'tool', 'tool_call_id' => 'a', 'content' => 'A'],
    ['role' => 'tool', 'tool_call_id' => 'b', 'content' => ''],
    ['role' => 'user', 'content' => 'and then?'],
    ['role' => 'system', 'content' => 'Be brief.'],
], [], 1000)['messages'];
check('parallel results share one user turn, with the next message after them',
    count($shaped) === 3 && array_column($shaped[2]['content'], 'type') === ['tool_result', 'tool_result', 'text', 'text'],
    json_encode($shaped));
check('arguments sent as a JSON string are decoded, and none become an object',
    ($shaped[1]['content'][1]['input'] ?? null) === ['k' => 1] && json_encode($shaped[1]['content'][0]['input']) === '{}',
    json_encode($shaped[1]));
check('an empty result is still a result', ($shaped[2]['content'][1]['content'] ?? '') === '(no output)');
check('a later system message is said in a user turn', ($shaped[2]['content'][3]['text'] ?? '') === 'Be brief.', json_encode($shaped[2]));

$summarised = AnthropicWire::payload('m', [
    ['role' => 'system', 'content' => 'S'],
    ['role' => 'assistant', 'content' => "[Summary of the earlier turns]\nwe fixed the bug"],
    ['role' => 'user', 'content' => 'next'],
], [], 1000)['messages'];
check('a history opening on a summary still opens on a user turn',
    ($summarised[0]['role'] ?? '') === 'user' && ($summarised[1]['role'] ?? '') === 'assistant', json_encode($summarised));

// ---- a rewritten history loses its old thinking ----------------------------

$sent3 = [];
$platform3 = anthropic([
    reply(['text' => 'one']),
    reply(['text' => 'summary']),
    reply(['text' => 'two']),
    reply(['text' => 'three']),
], $sent3);

$long = [
    ['role' => 'system', 'content' => 'S'],
    ['role' => 'user', 'content' => 'read it'],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 't1', 'function' => ['name' => 'file_read', 'arguments' => ['path' => 'a']]]],
        AnthropicWire::THINKING => [['type' => 'thinking', 'thinking' => 'old', 'signature' => 'OLD']]],
    ['role' => 'tool', 'tool_call_id' => 't1', 'content' => str_repeat('x', 2000)],
];
$platform3->stream($long, [], fn() => null);
check('an untouched history keeps its thinking', str_contains(json_encode($sent3[0]['body']), '"OLD"'));

// A side pass — a summary — has a conversation of its own and changes nothing.
$platform3->stream([['role' => 'user', 'content' => 'summarise this']], []);

// Compaction elided the old tool output: the history the block was bound to is gone.
$long[3]['content'] = '[output moved out of the window]';
$long[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 't2', 'function' => ['name' => 'file_read', 'arguments' => ['path' => 'b']]]],
    AnthropicWire::THINKING => [['type' => 'thinking', 'thinking' => 'new', 'signature' => 'NEW']]];
$long[] = ['role' => 'tool', 'tool_call_id' => 't2', 'content' => 'B'];
$platform3->stream($long, [], fn() => null);
check('after a rewrite, the older thinking blocks are dropped', !str_contains(json_encode($sent3[2]['body']), '"OLD"'), json_encode($sent3[2]['body']['messages']));
check('the side pass did not count as a rewrite of the conversation', str_contains(json_encode($sent3[2]['body']), 'moved out of the window'));

$long[] = ['role' => 'assistant', 'content' => 'done', AnthropicWire::THINKING => [['type' => 'thinking', 'thinking' => 'later', 'signature' => 'LATER']]];
$long[] = ['role' => 'user', 'content' => 'thanks'];
$platform3->stream($long, [], fn() => null);
$body = json_encode($sent3[3]['body']);
check('blocks made after the rewrite are kept', str_contains($body, '"LATER"'), $body);
check('and the dropped ones stay dropped', !str_contains($body, '"OLD"'), $body);

// ---- blocks bound to another history: sent again without them, once -------

$sent4 = [];
$stale = anthropic([
    new MockResponse('{"type":"error","error":{"type":"invalid_request_error","message":"messages.1.content.0: Invalid `signature` in `thinking` block. The block is bound to a different conversation."}}', ['http_code' => 400]),
    reply(['text' => 'recovered']),
], $sent4);
$recovered = $stale->stream([
    ['role' => 'user', 'content' => 'hi'],
    ['role' => 'assistant', 'content' => 'hello', AnthropicWire::THINKING => [['type' => 'thinking', 'thinking' => 'x', 'signature' => 'STALE']]],
    ['role' => 'user', 'content' => 'again'],
], [], fn() => null);
check('a stale thinking block is dropped and the request sent again',
    count($sent4) === 2 && str_contains(json_encode($sent4[0]['body']), 'STALE') && !str_contains(json_encode($sent4[1]['body']), 'STALE'),
    json_encode(array_column($sent4, 'body')));
check('and the reply comes through', $recovered['content'] === 'recovered', json_encode($recovered));

$threw = null;
try {
    anthropic([new MockResponse('{"type":"error","error":{"type":"invalid_request_error","message":"max_tokens: too large"}}', ['http_code' => 400])])
        ->stream([['role' => 'user', 'content' => 'x']]);
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}
check('any other 400 is reported with Anthropic\'s own message', $threw !== null && str_contains($threw, 'max_tokens: too large'), (string) $threw);

// ---- the stream says otherwise ---------------------------------------------

$threw = null;
try {
    anthropic([new MockResponse([
        ev(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 5, 'output_tokens' => 0]]]),
        ev(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'Internal failure in the model']]),
    ], ['response_headers' => ['content-type' => 'text/event-stream']])])->stream([['role' => 'user', 'content' => 'x']]);
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}
check('an error event mid-stream is raised, not taken for an answer', str_contains((string) $threw, 'Internal failure'), (string) $threw);

$sentOverload = [];
$overloaded = anthropic([
    new MockResponse([ev(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']])], ['response_headers' => ['content-type' => 'text/event-stream']]),
    reply(['text' => 'back']),
], $sentOverload)->stream([['role' => 'user', 'content' => 'x']], [], fn() => null);
check('an overload before any text is retried', count($sentOverload) === 2 && $overloaded['content'] === 'back', json_encode($overloaded));

$refused = anthropic([reply(['stop' => 'refusal'])])->stream([['role' => 'user', 'content' => 'x']], [], fn() => null);
check('a refusal with nothing written says so', str_contains($refused['content'], 'declined'), json_encode($refused));

// ---- models, and no embeddings ---------------------------------------------

$sent5 = [];
$listing = anthropic([new MockResponse(json_encode(['data' => [
    ['id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5', 'max_input_tokens' => 1000000, 'max_tokens' => 128000],
    ['id' => 'claude-haiku-4-5', 'display_name' => 'Claude Haiku 4.5', 'max_input_tokens' => 200000],
], 'has_more' => false]), ['response_headers' => ['content-type' => 'application/json']])], $sent5);
$models = $listing->catalogue();
check('the model list asks for every page at once', str_contains($sent5[0]['url'] ?? '', '/models?limit=1000'), $sent5[0]['url'] ?? '');
check('and sends the Anthropic headers there too', ($sent5[0]['headers']['x-api-key'] ?? '') === 'sk-ant-test', json_encode($sent5[0]['headers'] ?? []));
check('each model comes with its context window', ($models[1]->name ?? '') === 'claude-sonnet-5' && ($models[1]->contextLength ?? 0) === 1000000,
    json_encode(array_map(fn($m) => [$m->name, $m->contextLength], $models)));

$threw = false;
try {
    anthropic([])->embed(['word'], 'any');
} catch (UnknownEmbeddingModel) {
    $threw = true;
}
check('no embedding model, said the way detection understands', $threw);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
