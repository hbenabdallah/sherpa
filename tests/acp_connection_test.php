<?php

declare(strict_types=1);

namespace App\Tests\AcpConnectionTest;

use stdClass;

// JSON-RPC over stdio, as ACP frames it: one message per line, requests both
// ways, and a cancel that has to be heard while Sherpa waits on something else.

require __DIR__ . '/../vendor/autoload.php';

use App\Acp\AcpError;
use App\Acp\Connection;
use App\Runtime\Interrupt;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo "        ", str_replace("\n", "\n        ", substr($detail, 0, 400)), "\n";
    }
}

/** Sherpa's end, and the editor's: what one writes, the other reads. */
function pair(): array
{
    [$sherpa, $editor] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    return [new Connection($sherpa, $sherpa), $editor];
}

/** Every line the editor has been sent so far, decoded. */
function sent($editor): array
{
    stream_set_blocking($editor, false);
    $lines = '';
    while (($chunk = fread($editor, 65536)) !== false && $chunk !== '') {
        $lines .= $chunk;
    }

    return array_map(fn(string $l): mixed => json_decode($l, true), array_values(array_filter(explode("\n", $lines))));
}

function say($editor, array $message): void
{
    fwrite($editor, json_encode($message) . "\n");
}

// ---- framing ------------------------------------------------------------------------
[$connection, $editor] = pair();
say($editor, ['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => ['protocolVersion' => 1]]);
$message = $connection->receive(1.0);
check('a request arrives whole', ($message['method'] ?? '') === 'initialize' && $message['id'] === 0 && $message['params']['protocolVersion'] === 1, json_encode($message));

$connection->respond(0, ['protocolVersion' => 1]);
$connection->notify('session/update', ['sessionId' => 's', 'update' => ['content' => ['text' => "two\nlines"]]]);
$connection->respond(1, []);
$raw = '';
stream_set_blocking($editor, false);
usleep(10_000);
$raw = (string) fread($editor, 65536);
$lines = explode("\n", rtrim($raw, "\n"));
check('each message is one line, newline-terminated', count($lines) === 3 && str_ends_with($raw, "\n"), $raw);
check('a newline inside a string stays escaped', str_contains($lines[1], 'two\\nlines'), $lines[1]);
check('an empty result is an object, not a list', json_decode($lines[2])->result instanceof stdClass, $lines[2]);
$connection->notify('x', ['path' => '/src/Café.php']);
usleep(10_000);
check('paths and accents go out as they are', str_contains((string) fread($editor, 65536), '"/src/Café.php"'));

// Two messages in one read, and one split across two.
fwrite($editor, json_encode(['jsonrpc' => '2.0', 'method' => 'a']) . "\n" . json_encode(['jsonrpc' => '2.0', 'method' => 'b']) . "\n" . '{"jsonrpc":"2.0",');
$first = $connection->receive(1.0);
$second = $connection->receive(1.0);
check('two messages read at once are two messages', ($first['method'] ?? '') === 'a' && ($second['method'] ?? '') === 'b');
fwrite($editor, '"method":"c"}' . "\n");
check('a message split across reads is put back together', ($connection->receive(1.0)['method'] ?? '') === 'c');

check('waiting with nothing to read gives up at the timeout', $connection->receive(0.05) === null);

// Not JSON: said back as a parse error, and the connection goes on.
fwrite($editor, "not json\n");
say($editor, ['jsonrpc' => '2.0', 'method' => 'after']);
$after = $connection->receive(1.0);
$errors = sent($editor);
check('a line that is not JSON gets a parse error', ($errors[0]['error']['code'] ?? 0) === AcpError::PARSE_ERROR && array_key_exists('id', $errors[0]) && $errors[0]['id'] === null, json_encode($errors));
check('and the next message is still read', ($after['method'] ?? '') === 'after');

$connection->fail(7, new AcpError('No such session', AcpError::RESOURCE_NOT_FOUND));
$failed = sent($editor);
check(
    'a failure carries the request id, the code and the message',
    ($failed[0]['id'] ?? null) === 7 && $failed[0]['error']['code'] === -32002 && $failed[0]['error']['message'] === 'No such session',
    json_encode($failed),
);

// ---- Sherpa asking ----------------------------------------------------------------
// The editor answers after sending something else first: a notification that
// must queue, and a cancel that must be heard at once.
[$connection, $editor] = pair();
$interrupt = new Interrupt();
$interrupt->beginTurn();
$connection->intercept(function (array $message) use ($interrupt): bool {
    if ($message['method'] === 'session/cancel') {
        $interrupt->request();

        return true;
    }

    return false;
});

say($editor, ['jsonrpc' => '2.0', 'method' => 'session/cancel', 'params' => ['sessionId' => 's']]);
say($editor, ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'session/prompt', 'params' => []]);
say($editor, ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['outcome' => ['outcome' => 'cancelled']]]);
$answer = $connection->request('session/request_permission', ['sessionId' => 's']);
$asked = sent($editor);
check('a request goes out with its own id', ($asked[0]['method'] ?? '') === 'session/request_permission' && $asked[0]['id'] === 1, json_encode($asked));
check('and its answer comes back', ($answer['outcome']['outcome'] ?? '') === 'cancelled', json_encode($answer));
check('a cancel that arrived meanwhile was heard at once', $interrupt->requested());
check('a request that arrived meanwhile waits its turn', ($connection->receive(0.1)['id'] ?? null) === 9);

say($editor, ['jsonrpc' => '2.0', 'id' => 2, 'error' => ['code' => -32601, 'message' => 'Method not found']]);

try {
    $connection->request('fs/read_text_file', ['path' => '/x']);
    check('an error answer is thrown', false);
} catch (AcpError $e) {
    check('an error answer is thrown, with its code', $e->getCode() === -32601 && $e->getMessage() === 'Method not found');
}

// ---- a cancel heard through the interrupt, while nothing reads -----------------------
[$connection, $editor] = pair();
$interrupt = new Interrupt();
$interrupt->beginTurn();
$connection->intercept(function (array $message) use ($interrupt): bool {
    $interrupt->request();

    return true;
});
$interrupt->listenWith($connection->pump(...));
check('nothing arrived, nothing requested', !$interrupt->requested());
say($editor, ['jsonrpc' => '2.0', 'method' => 'session/cancel', 'params' => ['sessionId' => 's']]);
usleep(30_000);
check('a turn that only asks the interrupt hears the cancel', $interrupt->requested());

// ---- the editor going away ----------------------------------------------------------
[$connection, $editor] = pair();
fwrite($editor, '{"jsonrpc":"2.0","method":"last"}');
fclose($editor);
check('a last message without a newline is still read', ($connection->receive(1.0)['method'] ?? '') === 'last');
check('then the end is reported', $connection->receive(1.0) === null && $connection->ended());

[$connection, $editor] = pair();
fclose($editor);

try {
    $connection->request('session/request_permission', []);
    check('asking an editor that is gone fails', false);
} catch (AcpError) {
    check('asking an editor that is gone fails rather than waiting forever', true);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
