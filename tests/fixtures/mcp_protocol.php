<?php
/**
 * What the stand-in MCP server answers, whatever pipe or socket it is reached
 * through.
 *
 * Two fixtures use it — one over stdio, one over HTTP — and they must agree on
 * every byte, otherwise a test proves that one transport works rather than that
 * the client does. The misbehaviours that belong to a transport (a process that
 * dies, a 500) stay in the fixture that can produce them.
 */

return new class {
    /** @var array<int, array<string, mixed>> */
    public array $tools;

    public function __construct()
    {
        $this->tools = [
            ['name' => 'echo', 'description' => 'Returns the text it received', 'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'text'  => ['type' => 'string', 'description' => 'The text to send back'],
                    'fois'  => ['type' => 'integer', 'description' => 'Combien de fois'],
                    'crier' => ['type' => 'boolean', 'description' => 'En majuscules'],
                ],
                'required' => ['text'],
            ]],
            ['name' => 'horloge', 'description' => 'Donne l\'heure', 'inputSchema' => ['type' => 'object', 'properties' => []]],
            ['name' => 'file_read', 'description' => 'Collision volontaire avec un tool natif', 'inputSchema' => ['type' => 'object', 'properties' => []]],
        ];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>|null null for a notification, which is
     *                                   answered by saying nothing
     */
    public function answer(array $request, string $mode = 'ok'): ?array
    {
        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;

        if ($method === 'notifications/initialized' || $id === null) {
            return null;
        }

        return match ($method) {
            'initialize' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => '2025-06-18',
                'capabilities'    => ['tools' => new stdClass()],
                'serverInfo'      => ['name' => 'faux-serveur', 'version' => '1.0'],
            ]],
            'tools/list' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $this->page($request, $mode)],
            'tools/call' => $this->call($request, $mode, $id),
            default      => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'unknown method']],
        };
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function page(array $request, string $mode): array
    {
        if ($mode !== 'paginated') {
            return ['tools' => $this->tools];
        }

        $cursor = $request['params']['cursor'] ?? null;

        return $cursor === null
            ? ['tools' => array_slice($this->tools, 0, 2), 'nextCursor' => 'page2']
            : ['tools' => array_slice($this->tools, 2)];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function call(array $request, string $mode, mixed $id): array
    {
        if ($mode === 'protoerror') {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'invalid parameter']];
        }

        if ($mode === 'toolerror') {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'content' => [['type' => 'text', 'text' => 'the file does not exist']],
                'isError' => true,
            ]];
        }

        $name = $request['params']['name'] ?? '';
        $args = $request['params']['arguments'] ?? [];

        if ($name !== 'echo') {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'content' => [['type' => 'text', 'text' => "appelé: {$name}"]],
            ]];
        }

        $text = (string) ($args['text'] ?? '');
        $fois = (int) ($args['fois'] ?? 1);
        $crier = (bool) ($args['crier'] ?? false);
        $out = implode(' ', array_fill(0, max(1, $fois), $crier ? mb_strtoupper($text) : $text));

        // A second block, on purpose: a result is a list of content, and a
        // client that only reads the first one drops what a tool sent.
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'content' => [
                ['type' => 'text', 'text' => $out],
                ['type' => 'image', 'mimeType' => 'image/png', 'data' => 'AAAA'],
            ],
        ]];
    }
};
