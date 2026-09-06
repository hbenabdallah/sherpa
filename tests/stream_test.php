<?php
// Verification of OllamaPlatform::stream() tool-call accumulation.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Platform\OllamaPlatform;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$pass = 0;
$fail = 0;

function check(string $label, $expected, $actual): void
{
    global $pass, $fail;
    $ok = $expected === $actual;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok) {
        echo "        expected: ", json_encode($expected), "\n";
        echo "        actual:   ", json_encode($actual), "\n";
    }
}

function platform(array $chunks): OllamaPlatform
{
    return new OllamaPlatform(new MockHttpClient(new MockResponse($chunks)), 'http://x', 'm');
}

// ---- 1. Text streamed across chunks, deliberately split mid-JSON-line -------
$tokens = [];
$msg = platform([
    '{"message":{"role":"assistant","content":"Hel',
    'lo "}}' . "\n" . '{"message":{"role":"assistant","content":"world"}}' . "\n",
    '{"message":{"role":"assistant","content":"!"},"done":true}',  // no trailing \n
])->stream([], [], function (string $t) use (&$tokens) { $tokens[] = $t; });

check('text reassembled across a split line', 'Hello world!', $msg['content']);
check('tokens delivered individually', ['Hello ', 'world', '!'], $tokens);
check('final line without newline is consumed', true, str_ends_with($msg['content'], '!'));

// ---- 2. Content accumulates even with no onToken callback ------------------
$msg = platform(['{"message":{"content":"silent"},"done":true}'])->stream([]);
check('content kept when onToken is null', 'silent', $msg['content']);

// ---- 3. Ollama-style tool call (arguments as an object) --------------------
$msg = platform([
    '{"message":{"role":"assistant","content":"","tool_calls":[{"function":{"name":"file_read","arguments":{"path":"a.php"}}}]},"done":true}',
])->stream([]);
check('single tool call captured', 1, count($msg['tool_calls'] ?? []));
check('tool name preserved', 'file_read', $msg['tool_calls'][0]['function']['name']);
check('object arguments preserved', ['path' => 'a.php'], $msg['tool_calls'][0]['function']['arguments']);

// ---- 4. THE REGRESSION: two tool calls arriving in separate chunks ---------
$msg = platform([
    '{"message":{"tool_calls":[{"function":{"name":"file_read","arguments":{"path":"a.php"}}}]}}' . "\n",
    '{"message":{"tool_calls":[{"function":{"name":"project_grep","arguments":{"pattern":"foo"}}}]},"done":true}',
])->stream([]);
check('both tool calls survive separate chunks', 2, count($msg['tool_calls'] ?? []));
check('first call is not clobbered', 'file_read', $msg['tool_calls'][0]['function']['name'] ?? null);
check('second call present', 'project_grep', $msg['tool_calls'][1]['function']['name'] ?? null);

// ---- 5. Two tool calls in one chunk ---------------------------------------
$msg = platform([
    '{"message":{"tool_calls":[' .
    '{"function":{"name":"a","arguments":{}}},' .
    '{"function":{"name":"b","arguments":{}}}]},"done":true}',
])->stream([]);
check('both calls in a single chunk kept', 2, count($msg['tool_calls'] ?? []));

// ---- 6. OpenAI-style: arguments streamed as string fragments by index ------
$msg = platform([
    '{"message":{"tool_calls":[{"index":0,"function":{"name":"file_read","arguments":"{\"pa"}}]}}' . "\n",
    '{"message":{"tool_calls":[{"index":0,"function":{"arguments":"th\":\"b.php\"}"}}]},"done":true}',
])->stream([]);
check('fragmented calls merge into one', 1, count($msg['tool_calls'] ?? []));
check('string arguments concatenated', '{"path":"b.php"}', $msg['tool_calls'][0]['function']['arguments'] ?? null);

// ---- 7. No tool calls => key absent, so AgentLoop takes the final-answer path
$msg = platform(['{"message":{"content":"done"},"done":true}'])->stream([]);
check('no tool_calls key on a plain reply', false, isset($msg['tool_calls']));

// ---- 8. Timeouts ----------------------------------------------------------
// Symfony's `timeout` is an idle timeout, and the only way to lift it is a
// negative value: HttpClientTrait turns that into 172800s, while 0 is taken
// literally as "no inactivity allowed" and fails at once. Local CPU inference
// is silent for the whole prompt-evaluation pass, which is exactly what an
// idle timeout measures — hence unlimited by default.
$seen = [];
$recorder = new MockHttpClient(function ($method, $url, $options) use (&$seen) {
    $seen[] = $options['timeout'] ?? null;

    return new MockResponse('{"message":{"content":"ok"},"done":true}');
});

$platform = new OllamaPlatform($recorder, 'http://x', 'm');
$platform->stream([]);
check('streaming inference is not capped at 180s', true, $seen[0] > 3600);

$explicit = new OllamaPlatform($recorder, 'http://x', 'm', 32768, 45.0);
$explicit->stream([]);
check('an explicit timeout is honoured', 45.0, $seen[1]);

// Liveness probes keep a short leash: hanging on them is worse than failing.
$platform->isAvailable();
check('the availability probe stays bounded', true, $seen[2] > 0 && $seen[2] <= 5);

// ---- 8b. keep_alive travels with every request -----------------------------
// Ollama's own default unloads the model after five minutes of quiet, which is
// shorter than reading a diff. Nothing noticed while a turn itself took
// minutes; on a machine where a turn takes seconds, it is the longest wait in
// the session and it is spent re-uploading weights that were already resident.
$bodies = [];
$spy = new MockHttpClient(function ($method, $url, $options) use (&$bodies) {
    $bodies[] = json_decode($options['body'] ?? '{}', true);

    return new MockResponse('{"message":{"content":"ok"},"done":true}');
});

(new OllamaPlatform($spy, 'http://x', 'm'))->stream([]);
check('keep_alive is sent by default', '30m', $bodies[0]['keep_alive'] ?? null);

(new OllamaPlatform($spy, 'http://x', 'm', 32768, -1.0, null, '2h'))->stream([]);
check('and it is configurable', '2h', $bodies[1]['keep_alive'] ?? null);

// ---- 8b-bis. Sampling: this is a structured-output system -------------------
// Left unset, Ollama applies roughly 0.8 — a creative-writing default on a
// system that only ever emits tool calls, JSON and summaries. And num_predict
// is what makes ContextBudget's response reservation true rather than hopeful.
check('temperature is sent, and low', true, ($bodies[0]['options']['temperature'] ?? 1.0) <= 0.3);
check('it is not zero, which traps some models in repetition loops',
    true, ($bodies[0]['options']['temperature'] ?? 0.0) > 0.0);
check('a reply is capped so the budget’s reservation is real',
    4096, $bodies[0]['options']['num_predict'] ?? null);
check('and num_ctx still travels with them', 32768, $bodies[0]['options']['num_ctx'] ?? null);

(new OllamaPlatform($spy, 'http://x', 'm', 32768, -1.0, null, '30m', 0.7, 512))->stream([]);
check('temperature is configurable', 0.7, $bodies[2]['options']['temperature'] ?? null);
check('so is the reply cap', 512, $bodies[2]['options']['num_predict'] ?? null);

// ---- 8b-ter. A call made with no arguments goes back as an object ----------
// PHP holds it as [], which json_encode writes as a list; Ollama's decoder
// wants an object there. The model's own call, echoed on the next request.
$raw = [];
$rawSpy = new MockHttpClient(function ($method, $url, $options) use (&$raw) {
    $raw[] = $options['body'] ?? '';

    return new MockResponse('{"message":{"content":"ok"},"done":true}');
});
(new OllamaPlatform($rawSpy, 'http://x', 'm'))->stream([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['id' => 'aaaaaaaaa', 'function' => ['name' => 'memory_recall', 'arguments' => []]],
        ['id' => 'bbbbbbbbb', 'function' => ['name' => 'file_read', 'arguments' => ['path' => 'a.php']]],
    ]],
    ['role' => 'tool', 'content' => 'rien', 'name' => 'memory_recall', 'tool_call_id' => 'aaaaaaaaa'],
]);
check('empty arguments are sent as an object', true, str_contains($raw[0], '"arguments":{}'));
check('never as a list', false, str_contains($raw[0], '"arguments":[]'));
check('arguments with keys are untouched', true, str_contains($raw[0], '"arguments":{"path":"a.php"}'));

// ---- 8b-quater. A thinking model is not silent, only unseen ---------------
$waits = [];
platform([
    '{"message":{"thinking":"voyons"}}' . "\n",
    '{"message":{"content":"ok"},"done":true}',
])->stream([], [], null, function (bool $reasoning) use (&$waits) {
    $waits[] = $reasoning;
});
check('Ollama\'s thinking field is reported as reasoning', true, in_array(true, $waits, true));

// ---- 8c. Throughput, from durations that were already on the wire ----------
// prompt_eval_duration and eval_duration arrive in the same final chunk as the
// counts ContextBudget reads, in nanoseconds. They were being parsed past.
$platform = platform([
    '{"message":{"content":"x"},"done":true,"prompt_eval_count":1000,"eval_count":50,'
    . '"prompt_eval_duration":2000000000,"eval_duration":5000000000}',
]);
$platform->stream([]);
$timings = $platform->lastTimings();

check('prefill throughput is derived from the durations', 500.0, $timings['prefill'] ?? null);
check('so is generation throughput', 10.0, $timings['decode'] ?? null);

// A backend that reports no durations must leave policy on its frugal branch
// rather than hand it a fabricated speed.
$platform = platform(['{"message":{"content":"x"},"done":true,"prompt_eval_count":10,"eval_count":2}']);
$platform->stream([]);
check('no durations reported means no timings', null, $platform->lastTimings());

// A phase that did not happen reports zero, which InferenceSpeed discards.
$platform = platform([
    '{"message":{"content":"x"},"done":true,"prompt_eval_count":0,"eval_count":40,'
    . '"prompt_eval_duration":0,"eval_duration":4000000000}',
]);
$platform->stream([]);
check('a fully cached prompt yields no prefill figure', 0.0, $platform->lastTimings()['prefill'] ?? null);
check('while generation is still measured', 10.0, $platform->lastTimings()['decode'] ?? null);

// ---- 8d. Residency: is the card actually being used? -----------------------
$ps = fn(string $json) => new OllamaPlatform(
    new MockHttpClient(new MockResponse($json, ['http_code' => 200])),
    'http://x',
    'qwen3-coder:30b',
);

$r = $ps('{"models":[{"name":"qwen3-coder:30b","size":20000000000,"size_vram":20000000000}]}')->residency();
check('a model fully on the card reads as such', true, $r?->isFullyOnGpu());

$r = $ps('{"models":[{"name":"qwen3-coder:30b","size":20000000000,"size_vram":12000000000}]}')->residency();
check('a spilled model is reported as partial', false, $r?->isFullyOnGpu());
check('and its share is the interesting number', 60, (int) round(($r?->gpuShare() ?? 0) * 100));

$r = $ps('{"models":[{"name":"qwen3-coder:30b","size":20000000000,"size_vram":0}]}')->residency();
check('no VRAM at all reads as CPU-only', true, $r?->isCpuOnly());

// Ollama answers with the fully-qualified name; matching strictly would report
// every session started on a bare name as having no card at all.
$tagged = new OllamaPlatform(
    new MockHttpClient(new MockResponse('{"models":[{"name":"mistral:latest","size":100,"size_vram":100}]}', ['http_code' => 200])),
    'http://x',
    'mistral',
);
check('a bare model name matches its :latest entry', true, $tagged->residency()?->isFullyOnGpu());

// Loading is lazy, so "not there yet" is the normal answer before the first
// reply — and it must not read as "no GPU".
$absent = new OllamaPlatform(
    new MockHttpClient(new MockResponse('{"models":[]}', ['http_code' => 200])),
    'http://x',
    'mistral',
);
check('a model not yet loaded reports nothing rather than CPU', null, $absent->residency());

// ---- 9. Silence must not end the stream ------------------------------------
// Polling for cancellation costs a poll timeout, and Symfony removes a response
// from the stream generator as soon as that generator yields a timeout chunk.
// Treating the end of the generator as the end of the reply truncates every
// answer that pauses — which on CPU inference is all of them. An empty string
// in a MockResponse body is exactly that idle-timeout chunk.
$tokens = [];
$msg = platform([
    '{"message":{"content":"avant"}}' . "\n",
    '',
    '{"message":{"content":" apres"}}' . "\n",
    '{"message":{"content":"","done":true},"prompt_eval_count":11,"eval_count":3}',
])->stream([], [], function (string $t) use (&$tokens) { $tokens[] = $t; });

check('a pause mid-reply does not truncate it', 'avant apres', $msg['content']);
check('tokens after the pause still reach the caller', ['avant', ' apres'], $tokens);

// The counts only ever arrive on the last line, so losing the tail also loses
// the calibration ContextBudget depends on.
$platform = platform([
    '{"message":{"content":"x"}}' . "\n",
    '',
    '{"message":{"content":"y"},"done":true,"prompt_eval_count":42,"eval_count":7}',
]);
$platform->stream([]);
check('usage survives a pause', ['prompt' => 42, 'completion' => 7], $platform->lastUsage());

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
