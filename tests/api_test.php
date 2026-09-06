<?php
// The hosted-API backend: OpenAI's /chat/completions dialect, which is what
// every provider but Ollama speaks. Nothing here reaches a network — the whole
// point of testing a paid backend is that it stays free.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Platform\OpenAiCompatiblePlatform;
use App\Platform\PlatformInterface;
use App\Runtime\Interrupt;
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
        echo '        ', substr($detail, 0, 300), "\n";
    }
}

/** One SSE event, as a provider writes it on the wire. */
function event(array $data): string
{
    return 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
}

function textChunk(string $text): string
{
    return event(['choices' => [['delta' => ['content' => $text]]]]);
}

/**
 * A platform wired to a scripted server, with every request body captured.
 *
 * @param array<int, MockResponse> $responses
 */
function api(array $responses, array &$sent = [], ?Interrupt $interrupt = null): OpenAiCompatiblePlatform
{
    $client = new MockHttpClient(function ($method, $url, $options) use (&$responses, &$sent) {
        $sent[] = [
            'method'  => $method,
            'url'     => $url,
            'body'    => json_decode($options['body'] ?? '{}', true),
            'headers' => $options['headers'] ?? [],
        ];

        return array_shift($responses) ?? new MockResponse('data: [DONE]' . "\n");
    });

    return new OpenAiCompatiblePlatform($client, 'https://api.example.com/v1/', 'un-modele', 'clé-secrète', 64000, 30.0, $interrupt);
}

function sse(array $chunks, array $info = []): MockResponse
{
    return new MockResponse($chunks, $info + ['response_headers' => ['content-type' => 'text/event-stream']]);
}

// ---- the contract ----------------------------------------------------------
check('the API backend satisfies the interface',
    is_subclass_of(OpenAiCompatiblePlatform::class, PlatformInterface::class));

// ---- 1. Text, split where a provider would split it ------------------------
$tokens = [];
$msg = api([sse([
    ': keep-alive' . "\n\n",                 // several providers send these
    textChunk('Bon') . 'data: {"choi',        // a line cut mid-JSON
    'ces":[{"delta":{"content":"jour"}}]}' . "\n\n",
    textChunk(' !'),
    'data: [DONE]' . "\n\n",
])])->stream([['role' => 'user', 'content' => 'salut']], [], function (string $t) use (&$tokens) {
    $tokens[] = $t;
});

check('text is reassembled across chunk boundaries', ($msg['content'] ?? '') === 'Bonjour !', json_encode($msg));
check('tokens are delivered as they arrive', $tokens === ['Bon', 'jour', ' !'], json_encode($tokens));
check('keep-alive comments and [DONE] are not content', !str_contains($msg['content'], 'DONE'));
check('a plain reply carries no tool_calls key', !isset($msg['tool_calls']));

// ---- 2. Tool calls, streamed as fragments ----------------------------------
// Arguments arrive as JSON text cut anywhere, and the index says which call a
// fragment belongs to — two calls can be streamed at once.
$msg = api([sse([
    event(['choices' => [['delta' => ['tool_calls' => [
        ['index' => 0, 'id' => 'call_a', 'function' => ['name' => 'file_read', 'arguments' => '']],
        ['index' => 1, 'id' => 'call_b', 'function' => ['name' => 'project_grep', 'arguments' => '']],
    ]]]]]),
    event(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"pa']]]]]]]),
    event(['choices' => [['delta' => ['tool_calls' => [['index' => 1, 'function' => ['arguments' => '{"pattern":"x"}']]]]]]]),
    event(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'th":"a.php"}']]]]]]]),
    'data: [DONE]' . "\n\n",
])])->stream([]);

$calls = $msg['tool_calls'] ?? [];
check('both streamed calls survive', count($calls) === 2, json_encode($calls));
check('fragments are reassembled into the right call',
    ($calls[0]['function']['arguments'] ?? null) === ['path' => 'a.php'], json_encode($calls));
check('calls keep the order their indexes give them',
    ($calls[1]['function']['name'] ?? null) === 'project_grep', json_encode($calls));
check('the ids the server issued are kept, since it checks results against them',
    ($calls[0]['id'] ?? null) === 'call_a' && ($calls[1]['id'] ?? null) === 'call_b', json_encode($calls));
check('arguments reach the loop decoded, as the contract says',
    is_array($calls[1]['function']['arguments'] ?? null), json_encode($calls));

// A call with no arguments at all: "" would decode to nothing.
$msg = api([sse([
    event(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'c1', 'function' => ['name' => 'memory_recall', 'arguments' => '']]]]]]]),
    'data: [DONE]' . "\n\n",
])])->stream([]);
check('a call made with no arguments yields an empty array, not a string',
    ($msg['tool_calls'][0]['function']['arguments'] ?? null) === [], json_encode($msg));

// Gemini signs each call it makes while thinking, and refuses the next
// request (HTTP 400, "missing a thought_signature") unless it gets it back.
$msg = api([sse([
    event(['choices' => [['delta' => ['role' => 'assistant', 'tool_calls' => [[
        'index' => 0, 'id' => 'function-call-1', 'type' => 'function',
        'function' => ['name' => 'list_dir', 'arguments' => '{"path":"."}'],
        'extra_content' => ['google' => ['thought_signature' => 'c2lnbmF0dXJl']],
    ]]]]]]),
    'data: [DONE]' . "\n\n",
])])->stream([]);
check('a thought signature on a call is kept with it',
    ($msg['tool_calls'][0]['extra_content']['google']['thought_signature'] ?? null) === 'c2lnbmF0dXJl', json_encode($msg));

$signed = [];
api([sse([textChunk('ok'), 'data: [DONE]' . "\n\n"])], $signed)->stream([
    ['role' => 'user', 'content' => 'liste'],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [$msg['tool_calls'][0] + ['id' => 'function-call-1']]],
    ['role' => 'tool', 'content' => 'a.php', 'tool_call_id' => 'function-call-1'],
]);
check('and handed back on the next request, as it came',
    ($signed[0]['body']['messages'][1]['tool_calls'][0]['extra_content']['google']['thought_signature'] ?? null) === 'c2lnbmF0dXJl',
    json_encode($signed[0]['body']['messages'][1] ?? []));

// ---- 3. What the server is sent --------------------------------------------
$sent = [];
api([sse([textChunk('ok'), 'data: [DONE]' . "\n\n"])], $sent)->stream(
    [
        ['role' => 'system', 'content' => 'PROMPT'],
        ['role' => 'assistant', 'content' => '', 'tool_calls' => [
            ['id' => 'call_a', 'function' => ['name' => 'file_read', 'arguments' => ['path' => 'a.php']]],
            ['id' => 'call_b', 'function' => ['name' => 'memory_recall', 'arguments' => []]],
        ]],
        ['role' => 'tool', 'content' => 'contenu', 'name' => 'file_read', 'tool_call_id' => 'call_a'],
    ],
    [['type' => 'function', 'function' => ['name' => 'file_read']]],
);

$body = $sent[0]['body'];
check('the request goes to /chat/completions',
    str_ends_with($sent[0]['url'], '/v1/chat/completions'), $sent[0]['url']);
check('the key travels as a bearer token',
    in_array('Authorization: Bearer clé-secrète', $sent[0]['headers'], true), json_encode($sent[0]['headers']));
check('streaming is asked for', ($body['stream'] ?? null) === true);
check('and the token counts with it', ($body['stream_options']['include_usage'] ?? null) === true, json_encode($body));
check('tool schemas are passed through', isset($body['tools'][0]));

$assistant = $body['messages'][1];
check('a call from a provider that attached nothing carries nothing extra',
    !array_key_exists('extra_content', $assistant['tool_calls'][0] ?? []), json_encode($assistant));
check('a call declares its type, as this dialect requires',
    ($assistant['tool_calls'][0]['type'] ?? null) === 'function', json_encode($assistant));
check('arguments travel as JSON text, not as an object',
    ($assistant['tool_calls'][0]['function']['arguments'] ?? null) === '{"path":"a.php"}', json_encode($assistant));
check('and an argument-less call is an object in that text, never a list',
    ($assistant['tool_calls'][1]['function']['arguments'] ?? null) === '{}', json_encode($assistant));

$toolMessage = $body['messages'][2];
check('a result names the call it answers', ($toolMessage['tool_call_id'] ?? null) === 'call_a', json_encode($toolMessage));
check('and carries nothing else: some providers reject "name" on a tool message',
    array_keys($toolMessage) === ['role', 'tool_call_id', 'content'], json_encode($toolMessage));

// A server that wants no key at all — vLLM, LM Studio, llama.cpp.
$sentOpen = [];
$open = new OpenAiCompatiblePlatform(
    new MockHttpClient(function ($method, $url, $options) use (&$sentOpen) {
        $sentOpen[] = $options['headers'] ?? [];

        return sse([textChunk('ok'), 'data: [DONE]' . "\n\n"]);
    }),
    'http://localhost:8000/v1',
    'local',
);
$open->stream([]);
check('no key means no Authorization header',
    !str_contains(json_encode($sentOpen), 'Authorization'), json_encode($sentOpen));
check('and the backend is named after its host', $open->name() === 'localhost', $open->name());

// The address can be set after construction: recorded on this machine, or
// typed at the first-run question.
$moved = [];
$movable = new OpenAiCompatiblePlatform(
    new MockHttpClient(function ($method, $url) use (&$moved) {
        $moved[] = $url;

        return sse([textChunk('ok'), 'data: [DONE]' . "\n\n"]);
    }),
    '',
    'm',
);
check('with no address, the platform says so rather than sending anywhere', !$movable->isAvailable() && $movable->name() === "l'API");
$movable->useEndpoint('https://nouvelle.example/v1/');
$movable->stream([]);
check('pointing it at an address sends requests there',
    ($moved[0] ?? '') === 'https://nouvelle.example/v1/chat/completions', json_encode($moved));
check('and it can tell whether it has a key', !$movable->hasKey());

// ---- 4. Counts and throughput ----------------------------------------------
// The usage chunk arrives on its own, with an empty choices list.
$platform = api([sse([
    textChunk('réponse'),
    event(['choices' => [], 'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 42]]),
    'data: [DONE]' . "\n\n",
])]);
$platform->stream([]);

check('token counts are read from the usage chunk',
    $platform->lastUsage() === ['prompt' => 1200, 'completion' => 42], json_encode($platform->lastUsage()));
check('throughput is timed here, since no API reports durations',
    $platform->lastTimings() !== null, json_encode($platform->lastTimings()));
check('and it is a rate, not a duration',
    ($platform->lastTimings()['prefill'] ?? 0.0) > 0.0, json_encode($platform->lastTimings()));

$silent = api([sse([textChunk('réponse'), 'data: [DONE]' . "\n\n"])]);
$silent->stream([]);
check('a server that counts nothing leaves the budget uncalibrated rather than wrong',
    $silent->lastUsage() === null && $silent->lastTimings() === null);

check('residency means nothing on rented hardware, and says so', $platform->residency() === null);
check('the window is the one declared, since the API takes no such parameter',
    $platform->contextWindow() === 64000, (string) $platform->contextWindow());

// ---- 5. The dialect is not quite one language ------------------------------
// OpenAI's reasoning models refuse max_tokens and want max_completion_tokens.
// A hardcoded list of fussy models would be stale within the month; the
// rejection itself says what to do.
$sent = [];
$platform = api([
    new MockResponse(
        '{"error":{"message":"Unsupported parameter: \'max_tokens\' is not supported with this model. Use \'max_completion_tokens\' instead.","type":"invalid_request_error"}}',
        ['http_code' => 400],
    ),
    sse([textChunk('ok'), 'data: [DONE]' . "\n\n"]),
], $sent);
$msg = $platform->stream([['role' => 'user', 'content' => 'salut']]);

check('a rejected parameter is renamed and the request retried', ($msg['content'] ?? '') === 'ok', json_encode($msg));
check('the first attempt asked the way most servers want', isset($sent[0]['body']['max_tokens']));
check('the second asked the way this one does',
    isset($sent[1]['body']['max_completion_tokens']) && !isset($sent[1]['body']['max_tokens']), json_encode($sent[1]['body']));
check('and the conversation was not altered by the retry',
    $sent[1]['body']['messages'] === $sent[0]['body']['messages']);

// Learned once, not renegotiated on every turn.
$platform->stream([['role' => 'user', 'content' => 'encore']]);
check('what the server refused is remembered for the session',
    isset($sent[2]['body']['max_completion_tokens']) && count($sent) === 3, (string) count($sent));

// Same for a model that only accepts its own temperature.
$sent = [];
$platform = api([
    new MockResponse('{"error":{"message":"Unsupported value: \'temperature\' does not support 0.15 with this model."}}', ['http_code' => 400]),
    sse([textChunk('ok'), 'data: [DONE]' . "\n\n"]),
], $sent);
$platform->stream([]);
check('a temperature the model refuses is dropped rather than fatal',
    !isset($sent[1]['body']['temperature']) && isset($sent[0]['body']['temperature']), json_encode($sent[1]['body'] ?? []));

// A 400 that is not about a parameter is a real error and must surface.
$failed = null;
try {
    api([new MockResponse('{"error":{"message":"messages: at least one message is required"}}', ['http_code' => 400])])->stream([]);
} catch (Throwable $e) {
    $failed = $e;
}
check('any other refusal is reported, not silently retried', $failed instanceof RuntimeException);
check('and it quotes what the provider said',
    str_contains($failed?->getMessage() ?? '', 'at least one message is required'), $failed?->getMessage() ?? '');

// ---- 6. Failures a person can act on ---------------------------------------
$cases = [
    [401, '{"error":{"message":"Incorrect API key provided"}}', 'Key refused'],
    [404, '{"error":{"message":"The model does not exist"}}', 'Model or address unknown'],
    [500, '{"error":{"message":"server had an error"}}', "Failure on api.example.com's side"],
];
foreach ($cases as [$status, $body, $expected]) {
    $thrown = null;
    try {
        api([new MockResponse($body, ['http_code' => $status])])->stream([]);
    } catch (Throwable $e) {
        $thrown = $e;
    }
    check("HTTP {$status} is explained rather than dumped",
        $thrown !== null && str_contains($thrown->getMessage(), $expected), $thrown?->getMessage() ?? 'nothing');
}

// A model name is the provider's, and some providers cannot be asked whether
// they know it — Cloudflare answers 405 to the catalogue call, so a typo only
// surfaces here. The sentence that surfaces it has to say where to fix it.
$unknownModel = null;
try {
    api([new MockResponse(
        '{"errors":[{"message":"AiError: No such model: No such model glm-4.7-flash or task"}]}',
        ['http_code' => 400],
    )])->stream([]);
} catch (Throwable $e) {
    $unknownModel = $e->getMessage();
}
check('a model the provider never heard of names the model in force',
    str_contains($unknownModel ?? '', 'The model in force is'), $unknownModel ?? 'rien');
check('and says where to change it',
    str_contains($unknownModel ?? '', '/model'), $unknownModel ?? 'rien');

// The same sentence must not appear on every unrelated failure: a hint that
// fires always is noise, and noise is what gets skipped when it matters.
$unrelated = null;
try {
    api([new MockResponse('{"error":{"message":"rate limit exceeded"}}', ['http_code' => 429])])->stream([]);
} catch (Throwable $e) {
    $unrelated = $e->getMessage();
}
check('but an unrelated failure gets no such hint',
    !str_contains($unrelated ?? '', 'The model in force'), $unrelated ?? 'rien');

$leaky = null;
try {
    api([new MockResponse('{"error":{"message":"Incorrect API key provided: clé-secrète"}}', ['http_code' => 401])])->stream([]);
} catch (Throwable $e) {
    $leaky = $e->getMessage();
}
check('the message is the provider\'s own, quoted as-is', str_contains($leaky ?? '', 'Incorrect API key'), $leaky ?? '');

// A rate limit is the one failure worth waiting out: the request was well
// formed and the conversation has not changed.
$sent = [];
$rateLimited = api([
    new MockResponse('{"error":{"message":"Rate limit reached"}}', ['http_code' => 429, 'response_headers' => ['retry-after' => '1']]),
    sse([textChunk('enfin'), 'data: [DONE]' . "\n\n"]),
], $sent);
$started = microtime(true);
$msg = $rateLimited->stream([]);
check('a rate limit is waited out and the request repeated', ($msg['content'] ?? '') === 'enfin', json_encode($msg));
check('and the wait was the one the server asked for', microtime(true) - $started >= 1.0);

// ---- 7. Ctrl+C -------------------------------------------------------------
$interrupt = new Interrupt();
$interrupt->beginTurn();
$ignored = [];
$stopped = api([sse([textChunk('début'), textChunk('suite'), 'data: [DONE]' . "\n\n"])], $ignored, $interrupt);
$interrupt->request();
$msg = $stopped->stream([]);
check('an interrupted reply comes back rather than hanging', is_array($msg), json_encode($msg));
check('and nothing is timed from a cancelled request', $stopped->lastTimings() === null);
$interrupt->endTurn();

// ---- 8. Choosing a model ---------------------------------------------------
$catalogue = api([new MockResponse(json_encode(['data' => [
    ['id' => 'modele-b'],
    ['id' => 'modele-a', 'context_length' => 200000],
]]), ['response_headers' => ['content-type' => 'application/json']])]);

$models = $catalogue->catalogue();
check('the catalogue is read from /models', count($models) === 2, json_encode(array_map(fn($m) => $m->name, $models)));
check('a window is read where the server carries one',
    ($models[0]->name === 'modele-a' && $models[0]->contextLength === 200000), json_encode($models[0]));
check('and stays unknown where it does not', $models[1]->contextLength === null);
check('a model with no capability list is not refused: absence is not evidence',
    $models[0]->supportsTools() && $models[0]->capabilitiesUnknown());

$unknown = api([
    new MockResponse('{"error":{"message":"not found"}}', ['http_code' => 404]),
    new MockResponse(json_encode(['data' => [['id' => 'autre']]]), ['response_headers' => ['content-type' => 'application/json']]),
]);
check('a name the server does not serve is reported as unknown, not invented',
    $unknown->describeModel('inexistant') === null);

$available = api([new MockResponse(json_encode(['data' => []]), ['response_headers' => ['content-type' => 'application/json']])]);
check('availability is one cheap request that spends no tokens', $available->isAvailable());
check('and an unreachable server is a false, not an exception',
    !api([new MockResponse('', ['http_code' => 503])])->isAvailable());

// ---- 9. A real provider, recorded -----------------------------------------
// Cloudflare Workers AI, glm-4.7-flash, asked to read a file. Recorded once
// from the live API so these checks hold against what a provider actually
// sends, not against what its documentation suggests. Three things in it
// differ from OpenAI's own stream, and each broke an assumption.
$recorded = file_get_contents(__DIR__ . '/fixtures/cloudflare_tool_call.sse');
$cloudflare = api([sse(str_split($recorded, 97))]);   // cut at arbitrary points, as a network does
$msg = $cloudflare->stream([['role' => 'user', 'content' => 'Lis le fichier README.md du projet.']]);

check('the recorded tool call comes through',
    ($msg['tool_calls'][0]['function']['name'] ?? null) === 'file_read'
    && ($msg['tool_calls'][0]['function']['arguments'] ?? null) === ['path' => 'README.md'], json_encode($msg));
check('with the id the provider issued', ($msg['tool_calls'][0]['id'] ?? null) === 'chatcmpl-tool-9b8f902f09dbecaf');
// The model says what it is about to do, which is the reply; the thinking it
// did first ("L'utilisateur me demande…") is not, and must stay out of it.
check('the words it addressed to the user are kept',
    ($msg['content'] ?? '') === 'Je vais lire le fichier README.md du projet pour vous.', json_encode($msg['content'] ?? null));
check('and none of its reasoning leaks into them', !str_contains($msg['content'] ?? '', 'utilisateur'));

// Usage on every chunk: the prompt count first, per-token figures, a chunk of
// zeros, the totals last. Taking the last one seen is right by luck here;
// taking the largest is right by construction.
check('usage sent on every chunk still adds up to the totals',
    $cloudflare->lastUsage() === ['prompt' => 177, 'completion' => 53], json_encode($cloudflare->lastUsage()));

$zerosLast = api([sse([
    event(['choices' => [['delta' => ['content' => 'a']]], 'usage' => ['prompt_tokens' => 177, 'completion_tokens' => 1]]),
    event(['choices' => [['delta' => ['content' => 'b']]], 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 2]]),
    event(['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0]]),
    'data: [DONE]' . "\n\n",
])]);
$zerosLast->stream([]);
check('a trailing chunk of zeros does not wipe the counts',
    $zerosLast->lastUsage() === ['prompt' => 177, 'completion' => 2], json_encode($zerosLast->lastUsage()));

// Reasoning ends the prompt-reading wait: counting it as prefill would make a
// fast server look slow in proportion to how hard it thought.
check('a reply that only reasoned before its tool call is still timed',
    $cloudflare->lastTimings() !== null, json_encode($cloudflare->lastTimings()));

// A reasoning model is silent on screen, not idle: the caller hears about it.
$waits = [];
api([sse(str_split($recorded, 97))])->stream([], [], null, function (bool $reasoning) use (&$waits) {
    $waits[] = $reasoning;
});
check('reasoning is reported to whoever is waiting', in_array(true, $waits, true), json_encode(array_values(array_unique($waits))));

// Cloudflare publishes no /models (405), and reports errors as errors[].
$noList = api([new MockResponse('', ['http_code' => 405])]);
check('a server that publishes no model list is still available', $noList->isAvailable());

$noListEither = api([
    new MockResponse('', ['http_code' => 405]),   // /models/{id}
    new MockResponse('', ['http_code' => 405]),   // /models
]);
$info = $noListEither->describeModel('@cf/zai-org/glm-4.7-flash');
check('and a model named there is taken on trust rather than called unknown',
    $info !== null && $info->name === '@cf/zai-org/glm-4.7-flash' && $info->capabilitiesUnknown());

check('a refused key is still unavailable', !api([new MockResponse('', ['http_code' => 401])])->isAvailable());

$planError = null;
try {
    api([new MockResponse(json_encode(['errors' => [['message' => 'AiError: Model @cf/x is not available on the Workers Free plan', 'code' => 5035]], 'success' => false]), ['http_code' => 403])])->stream([]);
} catch (Throwable $e) {
    $planError = $e->getMessage();
}
check('Cloudflare\'s error list is quoted like any other provider\'s',
    str_contains($planError ?? '', 'not available on the Workers Free plan'), $planError ?? '');
check('and a 403 is not called a bad key, since here it is a plan limit',
    !str_contains($planError ?? '', 'Key refused') && str_contains($planError ?? '', 'Access refused'), $planError ?? '');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
