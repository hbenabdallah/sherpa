<?php

declare(strict_types=1);

// A model that takes a second over every request, then reports on the task it
// was given, with as many prompt tokens as the task has characters. Served by
// `php -S` with workers in delegate_test, so that two sub-agents can be
// answered at once.
$body = json_decode((string) file_get_contents('php://input'), true);
$task = (string) end($body['messages'])['content'];
sleep(1);
header('Content-Type: text/event-stream');
echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => "report on {$task}"]]]]) . "\n\n";
echo 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => strlen($task), 'completion_tokens' => 3]]) . "\n\n";
echo "data: [DONE]\n\n";
