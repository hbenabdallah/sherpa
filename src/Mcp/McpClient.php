<?php

namespace App\Mcp;

/**
 * The MCP side of the conversation: handshake, tool discovery, tool calls.
 *
 * Deliberately synchronous. Sherpa's loop is one turn at a time and a tool call
 * blocks it anyway, so an event loop would buy nothing and cost the ability to
 * reason about what happens when a server misbehaves.
 */
final class McpClient
{
    /**
     * The revision this client speaks. A server that prefers another one says
     * so in its reply; MCP asks the client to accept it or disconnect, and
     * disconnecting over a version nobody has read yet helps no one.
     */
    private const PROTOCOL_VERSION = '2025-06-18';

    private int $nextId = 1;
    private bool $ready = false;
    private ?string $serverVersion = null;

    /**
     * @param float $handshakeTimeout Starting up and listing tools: a server
     *                                that is not ready by now is not coming.
     * @param float $callTimeout      Doing actual work, which may legitimately
     *                                be slow — but never unbounded. Unlike the
     *                                local model, this is not a wait the user
     *                                chose to accept.
     */
    public function __construct(
        public readonly ServerConfig $config,
        private readonly StdioTransport $transport,
        private readonly float $handshakeTimeout = 15.0,
        private readonly float $callTimeout = 120.0,
    ) {}

    public static function for(ServerConfig $config, float $handshakeTimeout = 15.0, float $callTimeout = 120.0): self
    {
        return new self($config, new StdioTransport($config), $handshakeTimeout, $callTimeout);
    }

    public function connect(): void
    {
        if ($this->ready) {
            return;
        }

        $this->transport->start();

        $result = $this->request('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => ['tools' => new \stdClass()],
            'clientInfo'      => ['name' => 'sherpa', 'version' => '0.1.0'],
        ], $this->handshakeTimeout);

        $this->serverVersion = is_string($result['protocolVersion'] ?? null)
            ? $result['protocolVersion']
            : null;

        // The server is not allowed to be asked anything until it has been told
        // the handshake is over.
        $this->notify('notifications/initialized');

        $this->ready = true;
    }

    /**
     * @return array<int, array{name: string, description: string, schema: array<string, mixed>}>
     */
    public function listTools(): array
    {
        $this->connect();

        $tools = [];
        $cursor = null;

        // Paginated: a server with many tools returns them a page at a time,
        // and stopping at the first page would hide the rest without saying so.
        do {
            $params = $cursor === null ? [] : ['cursor' => $cursor];
            $result = $this->request('tools/list', $params, $this->handshakeTimeout);

            foreach ($result['tools'] ?? [] as $tool) {
                if (!is_array($tool) || !is_string($tool['name'] ?? null)) {
                    continue;
                }

                $tools[] = [
                    'name'        => $tool['name'],
                    'description' => (string) ($tool['description'] ?? ''),
                    'schema'      => is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [],
                ];
            }

            $cursor = is_string($result['nextCursor'] ?? null) ? $result['nextCursor'] : null;
        } while ($cursor !== null && count($tools) < 500);

        return $tools;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{text: string, isError: bool}
     */
    public function callTool(string $name, array $arguments): array
    {
        $this->connect();

        $result = $this->request('tools/call', [
            'name'      => $name,
            // An empty PHP array encodes as [], and the schema says object.
            'arguments' => $arguments === [] ? new \stdClass() : $arguments,
        ], $this->callTimeout);

        return [
            'text'    => $this->flatten($result['content'] ?? []),
            'isError' => (bool) ($result['isError'] ?? false),
        ];
    }

    public function serverProtocolVersion(): ?string
    {
        return $this->serverVersion;
    }

    public function close(): void
    {
        $this->ready = false;
        $this->transport->close();
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function request(string $method, array $params, float $timeout): array
    {
        $id = $this->nextId++;

        $this->transport->send([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'method'  => $method,
            'params'  => $params === [] ? new \stdClass() : $params,
        ]);

        $deadline = microtime(true) + $timeout;

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new McpException(
                    "Serveur MCP « {$this->config->name} » : pas de réponse à {$method} en {$timeout}s."
                );
            }

            $message = $this->transport->receive($remaining);
            if ($message === null) {
                continue;
            }

            // Notifications and the server's own requests share this stream.
            // Anything that is not the answer to this id is not ours to read.
            if (($message['id'] ?? null) !== $id) {
                continue;
            }

            if (isset($message['error'])) {
                $error = $message['error'];
                $text = is_array($error) ? ($error['message'] ?? json_encode($error)) : (string) $error;

                throw new McpException("Serveur MCP « {$this->config->name} » : {$method} a échoué : {$text}");
            }

            return is_array($message['result'] ?? null) ? $message['result'] : [];
        }
    }

    /** @param array<string, mixed> $params */
    private function notify(string $method, array $params = []): void
    {
        $this->transport->send([
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params === [] ? new \stdClass() : $params,
        ]);
    }

    /**
     * Tool results are a list of content blocks. The model reads text, so the
     * rest is named rather than dropped silently — an image nobody mentions
     * looks like a tool that returned nothing.
     *
     * @param array<int, mixed> $content
     */
    private function flatten(mixed $content): string
    {
        if (!is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }

            $parts[] = match ($block['type'] ?? '') {
                'text'  => (string) ($block['text'] ?? ''),
                'image' => '[image ' . ($block['mimeType'] ?? 'inconnue') . ', non affichable ici]',
                'audio' => '[audio ' . ($block['mimeType'] ?? 'inconnu') . ', non affichable ici]',
                'resource' => '[ressource ' . ($block['resource']['uri'] ?? '?') . ']',
                default => '',
            };
        }

        return trim(implode("\n", array_filter($parts, fn(string $p) => $p !== '')));
    }
}
