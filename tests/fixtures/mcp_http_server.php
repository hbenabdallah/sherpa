<?php
/**
 * The same stand-in MCP server, reached over HTTP instead of a pipe.
 *
 * Meant to be run by PHP's own server — `php -S 127.0.0.1:<port> <this file>` —
 * so the HTTP path can be tested with nothing installed and nothing reachable
 * outside the machine. What it answers comes from mcp_protocol.php, so a test
 * asserting the same thing over both transports really is asserting the same
 * thing.
 *
 * The behaviour is picked by the path, since a router gets no argv:
 *   /mcp             a well-behaved server
 *   /mcp/paginated   returns its tools two pages at a time
 *   /mcp/toolerror   answers tools/call with isError
 *   /mcp/protoerror  answers tools/call with a JSON-RPC error
 *   /mcp/http500     answers the handshake with a server error
 *   /mcp/unauth      demands an Authorization header, and says so
 */

$protocol = require __DIR__ . '/mcp_protocol.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$mode = trim(str_replace('/mcp', '', $path), '/') ?: 'ok';

// Closing a session is a DELETE, and a server that 404s it makes the client
// look like it failed when it was tidying up.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'DELETE') {
    http_response_code(204);

    return;
}

if ($mode === 'http500') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'serveur en carafe']);

    return;
}

if ($mode === 'unauth' && ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer jeton-de-test') {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'jeton manquant ou refusé']);

    return;
}

$body = file_get_contents('php://input') ?: '';
$request = json_decode($body, true);

if (!is_array($request)) {
    http_response_code(400);

    return;
}

$answer = $protocol->answer($request, $mode);

// A notification has no answer: 202 and nothing else is what the spec asks for,
// and what the client expects to be allowed to ignore.
if ($answer === null) {
    http_response_code(202);

    return;
}

header('Content-Type: application/json');
header('Mcp-Session-Id: session-de-test');
echo json_encode($answer, JSON_UNESCAPED_UNICODE);
