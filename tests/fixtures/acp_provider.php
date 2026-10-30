<?php

declare(strict_types=1);

// An OpenAI-compatible provider for `php -S`, scripted by the last user
// message, so `sherpa acp` can be driven end to end by tests/acp_test.php:
//
//   "which model"  "Model: " and the model the request was for
//   "propose" plan_ready with a two-step plan
//   a skill   "Skill applied: " and its name
//   images    "Saw N image(s) with: " and the text beside them
//   "patch"   file_patch on Invoice.php: return 0 → 42 ("again": 42 → 43)
//   "plan"    todo_write with three tasks
//   "slow"    a reply streamed a word every 0.2 s for ten seconds
//   anything  "Hello from the fake model."
//
// Once a tool result is the last message, it answers with that result's
// first line.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true) ?? [];

if ($path === '/v1/models') {
    header('Content-Type: application/json');
    echo json_encode(['data' => [['id' => 'fake-chat'], ['id' => 'fake-other']]]);

    return;
}

if ($path !== '/v1/chat/completions') {
    http_response_code(404);

    return;
}

header('Content-Type: text/event-stream');
$chunk = static fn(array $delta, ?string $finish = null) => 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]]]) . "\n\n";
$call = static function (string $name, array $arguments) use ($chunk): void {
    echo $chunk(['role' => 'assistant', 'tool_calls' => [[
        'index' => 0, 'id' => 'call' . substr(md5($name . json_encode($arguments)), 0, 5), 'type' => 'function',
        'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
    ]]]);
    echo $chunk([], 'tool_calls');
    echo "data: [DONE]\n\n";
};

$messages = $body['messages'] ?? [];
$last = end($messages) ?: [];

// The facts of a conversation, asked for once another one opens.
if (str_contains((string) ($messages[0]['content'] ?? ''), 'lasting technical facts')) {
    // A long one, from a conversation that said "take your time": ten seconds.
    if (str_contains(strtolower((string) ($last['content'] ?? '')), 'take your time')) {
        echo $chunk(['role' => 'assistant', 'content' => '[']);
        for ($i = 0; $i < 50; $i++) {
            @ob_flush();
            flush();
            if (connection_aborted()) {
                return;
            }
            usleep(200_000);
        }
    }
    echo $chunk(['role' => 'assistant', 'content' => '[{"key": "test-command", "value": "php tests/run.php"}]']);
    echo $chunk([], 'stop');
    echo "data: [DONE]\n\n";

    return;
}

if (($last['role'] ?? '') === 'tool') {
    echo $chunk(['role' => 'assistant', 'content' => 'Done: ' . strtok((string) ($last['content'] ?? ''), "\n")]);
    echo $chunk([], 'stop');
    echo "data: [DONE]\n\n";

    return;
}

// Text and images, as parts: says what it was given.
if (is_array($last['content'] ?? null)) {
    $parts = $last['content'];
    $images = array_filter($parts, static fn(array $part) => ($part['type'] ?? '') === 'image_url' && str_starts_with($part['image_url']['url'] ?? '', 'data:image/'));
    echo $chunk(['role' => 'assistant', 'content' => 'Saw ' . count($images) . ' image(s) with: ' . ($parts[0]['text'] ?? '')]);
    echo $chunk([], 'stop');
    echo "data: [DONE]\n\n";

    return;
}

$asked = strtolower((string) ($last['content'] ?? ''));

// Before "plan": in plan mode, every message carries a reminder that says it.
if (str_contains($asked, 'which model')) {
    echo $chunk(['role' => 'assistant', 'content' => 'Model: ' . ($body['model'] ?? '?')]);
    echo $chunk([], 'stop');
    echo "data: [DONE]\n\n";

    return;
}

if (preg_match('/<skill name="([^"]+)">/', $asked, $skill) === 1) {
    echo $chunk(['role' => 'assistant', 'content' => 'Skill applied: ' . $skill[1]]);
    echo $chunk([], 'stop');
    echo "data: [DONE]\n\n";

    return;
}

if (str_contains($asked, 'propose')) {
    $call('plan_ready', ['plan' => "1. Fix subtotal()\n2. Run the tests"]);

    return;
}

if (str_contains($asked, 'patch')) {
    $call('file_patch', str_contains($asked, 'again')
        ? ['path' => 'Invoice.php', 'search' => 'return 42;', 'replace' => 'return 43;']
        : ['path' => 'Invoice.php', 'search' => 'return 0;', 'replace' => 'return 42;']);

    return;
}

if (str_contains($asked, 'plan')) {
    $call('todo_write', ['tasks' => "[x] Read Invoice.php\n[>] Fix subtotal()\n[ ] Run the tests"]);

    return;
}

if (str_contains($asked, 'slow')) {
    for ($i = 0; $i < 50; $i++) {
        echo $chunk(['role' => 'assistant', 'content' => "word{$i} "]);
        @ob_flush();
        flush();
        if (connection_aborted()) {
            return;
        }
        usleep(200_000);
    }
    echo $chunk([], 'stop');
    echo "data: [DONE]\n\n";

    return;
}

echo $chunk(['role' => 'assistant', 'content' => 'Hello from ']);
echo $chunk(['content' => 'the fake model.']);
echo $chunk([], 'stop');
echo "data: [DONE]\n\n";
