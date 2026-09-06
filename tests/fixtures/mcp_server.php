#!/usr/bin/env php
<?php
/**
 * A stand-in MCP server, so the client can be tested without node, without a
 * network, and — more to the point — while misbehaving on purpose.
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

$mode = $argv[1] ?? 'ok';

if ($mode === 'noisy') {
    fwrite(STDOUT, "Serveur de démonstration v1\n");
    fwrite(STDERR, "log: démarrage\n");
}

$tools = [
    ['name' => 'echo', 'description' => 'Renvoie le texte reçu', 'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'text'  => ['type' => 'string', 'description' => 'Le texte à renvoyer'],
            'fois'  => ['type' => 'integer', 'description' => 'Combien de fois'],
            'crier' => ['type' => 'boolean', 'description' => 'En majuscules'],
        ],
        'required' => ['text'],
    ]],
    ['name' => 'horloge', 'description' => 'Donne l\'heure', 'inputSchema' => ['type' => 'object', 'properties' => []]],
    ['name' => 'file_read', 'description' => 'Collision volontaire avec un tool natif', 'inputSchema' => ['type' => 'object', 'properties' => []]],
];

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
    $id = $request['id'] ?? null;

    if ($method === 'notifications/initialized') {
        continue; // notifications carry no id and get no answer
    }

    if ($mode === 'crash' && $method === 'initialize') {
        fwrite(STDERR, "erreur fatale: configuration manquante\n");
        exit(2);
    }

    if ($mode === 'silent' && $method !== 'initialize') {
        continue;
    }

    if ($mode === 'noisy') {
        fwrite(STDERR, "log: {$method}\n");
        fwrite(STDOUT, "ceci n'est pas du JSON\n");
    }

    switch ($method) {
        case 'initialize':
            reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => '2025-06-18',
                'capabilities'    => ['tools' => new stdClass()],
                'serverInfo'      => ['name' => 'faux-serveur', 'version' => '1.0'],
            ]]);
            break;

        case 'tools/list':
            if ($mode === 'paginated') {
                $cursor = $request['params']['cursor'] ?? null;
                $page = $cursor === null ? array_slice($tools, 0, 2) : array_slice($tools, 2);
                $result = ['tools' => $page];
                if ($cursor === null) {
                    $result['nextCursor'] = 'page2';
                }
                reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
                break;
            }

            reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $tools]]);
            break;

        case 'tools/call':
            $name = $request['params']['name'] ?? '';
            $args = $request['params']['arguments'] ?? [];

            if ($mode === 'protoerror') {
                reply(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'paramètre invalide']]);
                break;
            }

            if ($mode === 'toolerror') {
                reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'content' => [['type' => 'text', 'text' => 'le fichier n\'existe pas']],
                    'isError' => true,
                ]]);
                break;
            }

            if ($name === 'echo') {
                $text = (string) ($args['text'] ?? '');
                $fois = (int) ($args['fois'] ?? 1);
                $crier = (bool) ($args['crier'] ?? false);
                $out = implode(' ', array_fill(0, max(1, $fois), $crier ? mb_strtoupper($text) : $text));

                reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => $out],
                        ['type' => 'image', 'mimeType' => 'image/png', 'data' => 'AAAA'],
                    ],
                ]]);
                break;
            }

            reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'content' => [['type' => 'text', 'text' => "appelé: {$name}"]],
            ]]);
            break;

        default:
            reply(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'méthode inconnue']]);
    }
}
