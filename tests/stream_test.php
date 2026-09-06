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

$platform->chat([]);
check('blocking inference is not capped at 180s', true, $seen[1] > 3600);

$explicit = new OllamaPlatform($recorder, 'http://x', 'm', 32768, 45.0);
$explicit->stream([]);
check('an explicit timeout is honoured', 45.0, $seen[2]);

// Liveness probes keep a short leash: hanging on them is worse than failing.
$platform->isAvailable();
check('the availability probe stays bounded', true, $seen[3] > 0 && $seen[3] <= 5);

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
