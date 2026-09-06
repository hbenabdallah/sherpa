#!/usr/bin/env php
<?php
/**
 * A stand-in MCP server over stdio, so the client can be tested without node,
 * without a network, and — more to the point — while misbehaving on purpose.
 *
 * What it answers lives in mcp_protocol.php, shared with the HTTP fixture: the
 * two transports must agree, or a passing test proves only that one of them
 * works. What is here is what only a child process can do wrong.
 *
 * The first argument picks a behaviour:
 *   ok          a well-behaved server
 *   noisy       prints a banner and log lines to stdout and stderr first
 *   paginated   returns its tools two pages at a time
 *   silent      accepts the handshake and then never answers
 *   crash       exits during the handshake
 *   toolerror   answers tools/call with isError
 *   protoerror  answers tools/call with a JSON-RPC error
 */

$protocol = require __DIR__ . '/mcp_protocol.php';
$mode = $argv[1] ?? 'ok';

if ($mode === 'noisy') {
    fwrite(STDOUT, "Serveur de démonstration v1\n");
    fwrite(STDERR, "log: démarrage\n");
}

function reply(array $message): void
{
    fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_UNICODE) . "\n");
    fflush(STDOUT);
}

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $request = json_decode($line, true);
    if (!is_array($request)) {
        continue;
    }

    $method = $request['method'] ?? '';

    if ($mode === 'crash' && $method === 'initialize') {
        fwrite(STDERR, "fatal error: missing configuration\n");
        exit(2);
    }

    if ($mode === 'silent' && $method !== 'initialize') {
        continue;
    }

    if ($mode === 'noisy') {
        fwrite(STDERR, "log: {$method}\n");
        fwrite(STDOUT, "this is not JSON\n");
    }

    $answer = $protocol->answer($request, $mode);

    if ($answer !== null) {
        reply($answer);
    }
}
