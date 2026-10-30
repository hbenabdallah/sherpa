<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\SlowProvider;

// A provider that reads the prompt in silence: no headers, nothing, for two
// seconds — then one short streamed reply. Served by `php -S` in api_test.
sleep(2);
header('Content-Type: text/event-stream');
echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'late but here']]]]) . "\n\n";
echo "data: [DONE]\n\n";
