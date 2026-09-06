<?php
// An OpenAI-compatible provider for `php -S`, scripted and stateless, so the
// single executable can be run end to end without a real model or any quota.
//
//   GET  /v1/models            a chat model and an embedding model
//   POST /v1/embeddings        one small vector per input
//   POST /v1/chat/completions  asks to write broken PHP; once a tool result is
//                              in the history, answers with the result's gist

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true) ?? [];

if ($path === '/v1/models') {
    header('Content-Type: application/json');
    echo json_encode(['data' => [['id' => 'fake-chat'], ['id' => 'text-embedding-3-small']]]);

    return;
}

if ($path === '/v1/embeddings') {
    $inputs = (array) ($body['input'] ?? []);
    header('Content-Type: application/json');
    echo json_encode(['data' => array_map(
        static fn(int $i, string $text) => ['index' => $i, 'embedding' => [strlen($text) % 7 / 7, 0.5, 0.25]],
        array_keys($inputs),
        $inputs,
    )]);

    return;
}

if ($path !== '/v1/chat/completions') {
    http_response_code(404);

    return;
}

header('Content-Type: text/event-stream');
$chunk = static fn(array $delta, ?string $finish = null) => 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]]]) . "\n\n";

$last = end($body['messages']) ?: [];
if (($last['role'] ?? '') === 'tool') {
    $refused = str_contains((string) ($last['content'] ?? ''), 'Parse error');
    echo $chunk(['role' => 'assistant', 'content' => $refused ? 'FAKE: the write was refused as invalid PHP.' : 'FAKE: the write went through.']);
    echo $chunk([], 'stop');
    echo "data: [DONE]\n\n";

    return;
}

echo $chunk(['role' => 'assistant', 'tool_calls' => [[
    'index' => 0, 'id' => 'call00001', 'type' => 'function',
    'function' => ['name' => 'file_write', 'arguments' => json_encode(['path' => 'broken.php', 'content' => "<?php\nuse A.B;\n"])],
]]]);
echo $chunk([], 'tool_calls');
echo "data: [DONE]\n\n";
