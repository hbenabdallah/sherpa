<?php

namespace App\Mcp;

use Mcp\Client as Sdk;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Exception\ExceptionInterface as SdkException;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Enum\ProtocolVersion;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * The MCP side of the conversation: handshake, tools, resources, prompts.
 *
 * The protocol is `mcp/sdk`, the official PHP SDK, which keeps the schema in
 * step with the spec — something a hand-written client cannot do without
 * becoming somebody's second job. What stays here is what the SDK has no
 * opinion about: the command is checked before a process is spawned, failures
 * are explained in the words of the server that failed, and every wire error
 * arrives as one McpException the rest of the program can survive.
 */
final class McpClient
{
    /**
     * The revision offered at the handshake, deliberately not the newest: from
     * 2025-11-25 a client replaces `initialize` with `server/discover`, which a
     * server that has not moved answers nothing useful to. One line to move up.
     */
    private const PROTOCOL_VERSION = ProtocolVersion::V2025_06_18;

    /** A server that pages forever does not get to page forever. */
    private const MAX_TOOLS = 500;
    private const MAX_RESOURCES = 500;
    private const MAX_PROMPTS = 200;

    private ?Sdk $client = null;
    private ?TransportInterface $transport = null;
    private readonly ServerLog $log;

    /**
     * @param float $handshakeTimeout A server not ready by now is not coming.
     * @param float $callTimeout      Real work, which may be slow but never
     *                                unbounded: not a wait the user chose.
     */
    public function __construct(
        public readonly ServerConfig $config,
        private readonly float $handshakeTimeout = 15.0,
        private readonly float $callTimeout = 120.0,
    ) {
        $this->log = new ServerLog();
    }

    public static function for(ServerConfig $config, float $handshakeTimeout = 15.0, float $callTimeout = 120.0): self
    {
        return new self($config, $handshakeTimeout, $callTimeout);
    }

    public function connect(): void
    {
        if ($this->client !== null) {
            return;
        }

        $transport = $this->config->isHttp() ? $this->http() : $this->stdio();

        $client = Sdk::builder()
            ->setClientInfo('sherpa', \App\Version::current())
            ->setProtocolVersion(self::PROTOCOL_VERSION)
            ->setInitTimeout($this->seconds($this->handshakeTimeout))
            ->setRequestTimeout($this->seconds($this->callTimeout))
            // A command that is on disk and still fails to speak the protocol
            // fails the same way three times. Retrying only delays the message.
            ->setMaxRetries(0)
            ->setLogger($this->log)
            ->build();

        try {
            $client->connect($transport);
        } catch (SdkException $e) {
            $transport->close();

            throw $this->failure('the handshake failed', $e);
        }

        $this->client = $client;
        $this->transport = $transport;
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
            try {
                $page = $this->client?->listTools($cursor);
            } catch (SdkException $e) {
                throw $this->failure('listing the tools failed', $e);
            }

            foreach ($page?->tools ?? [] as $tool) {
                $tools[] = [
                    'name'        => $tool->name,
                    'description' => (string) ($tool->description ?? ''),
                    'schema'      => $tool->inputSchema,
                ];
            }

            $cursor = $page?->nextCursor;
        } while ($cursor !== null && count($tools) < self::MAX_TOOLS);

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

        try {
            $result = $this->client?->callTool($name, $arguments);
        } catch (SdkException $e) {
            // A protocol-level error is not an answer: the tool never ran, and
            // saying otherwise would have the model act on a result that does
            // not exist. A tool that ran and failed comes back below, flagged.
            throw $this->failure("calling {$name} failed", $e);
        }

        return [
            'text'    => $this->flatten($result?->content ?? []),
            'isError' => (bool) $result?->isError,
        ];
    }

    /**
     * The resources the server offers, or none when it offers none: resources
     * are optional, and a server without them answers "unknown method".
     *
     * @return array<int, array{uri: string, name: string, description: string, mimeType: string}>
     */
    public function listResources(): array
    {
        $this->connect();

        $resources = [];
        $cursor = null;

        do {
            try {
                $page = $this->client?->listResources($cursor);
            } catch (SdkException) {
                return $resources;
            }

            foreach ($page?->resources ?? [] as $resource) {
                $resources[] = [
                    'uri'         => $resource->uri,
                    'name'        => (string) ($resource->title ?? $resource->name),
                    'description' => (string) ($resource->description ?? ''),
                    'mimeType'    => (string) ($resource->mimeType ?? ''),
                ];
            }

            $cursor = $page?->nextCursor;
        } while ($cursor !== null && count($resources) < self::MAX_RESOURCES);

        return $resources;
    }

    /**
     * A resource's content as text. Binary content is named, not decoded: the
     * model reads text.
     */
    public function readResource(string $uri): string
    {
        $this->connect();

        try {
            $result = $this->client?->readResource($uri);
        } catch (SdkException $e) {
            throw $this->failure("reading {$uri} failed", $e);
        }

        $parts = [];
        foreach ($result?->contents ?? [] as $content) {
            $parts[] = match (true) {
                $content instanceof TextResourceContents => $content->text,
                $content instanceof BlobResourceContents => '[' . ($content->mimeType ?? 'binary') . ', '
                    . strlen((string) base64_decode($content->blob, true)) . ' bytes, cannot be shown here]',
                default => '',
            };
        }

        return trim(implode("\n\n", array_filter($parts, fn(string $p) => $p !== '')));
    }

    /**
     * The prompts the server offers, or none — optional like resources.
     *
     * @return array<int, array{name: string, description: string, arguments: array<int, array{name: string, description: string, required: bool}>}>
     */
    public function listPrompts(): array
    {
        $this->connect();

        $prompts = [];
        $cursor = null;

        do {
            try {
                $page = $this->client?->listPrompts($cursor);
            } catch (SdkException) {
                return $prompts;
            }

            foreach ($page?->prompts ?? [] as $prompt) {
                $prompts[] = [
                    'name'        => $prompt->name,
                    'description' => (string) ($prompt->description ?? $prompt->title ?? ''),
                    'arguments'   => array_map(static fn($argument) => [
                        'name'        => $argument->name,
                        'description' => (string) ($argument->description ?? ''),
                        'required'    => (bool) $argument->required,
                    ], $prompt->arguments ?? []),
                ];
            }

            $cursor = $page?->nextCursor;
        } while ($cursor !== null && count($prompts) < self::MAX_PROMPTS);

        return $prompts;
    }

    /**
     * A prompt filled in by the server: its messages, each flattened to text.
     *
     * @param array<string, string> $arguments
     *
     * @return array<int, array{role: string, text: string}>
     */
    public function getPrompt(string $name, array $arguments): array
    {
        $this->connect();

        try {
            $result = $this->client?->getPrompt($name, $arguments);
        } catch (SdkException $e) {
            throw $this->failure("getting the prompt {$name} failed", $e);
        }

        $messages = [];
        foreach ($result?->messages ?? [] as $message) {
            $messages[] = [
                'role' => $message->role->value,
                'text' => $message->content instanceof EmbeddedResource && $message->content->resource instanceof TextResourceContents
                    ? $message->content->resource->text
                    : $this->flatten([$message->content]),
            ];
        }

        return $messages;
    }

    public function serverProtocolVersion(): ?string
    {
        return $this->client?->getProtocolVersion()?->value;
    }

    public function close(): void
    {
        $this->client?->disconnect();
        $this->transport?->close();

        $this->client = null;
        $this->transport = null;
    }

    /**
     * A child process, talked to over its pipes. The command is checked here
     * because proc_open() succeeds on one that does not exist, and the failure
     * would surface as a handshake timeout saying nothing about the typo.
     */
    private function stdio(): StdioTransport
    {
        if ($this->which($this->config->command) === null) {
            throw new McpException(
                "MCP server “{$this->config->name}”: command not found: {$this->config->command}"
            );
        }

        return new StdioTransport(
            command: $this->config->command,
            args: $this->config->args,
            cwd: null,
            env: $this->config->env === [] ? null : $this->config->env + $this->inheritedEnv(),
            logger: $this->log,
        );
    }

    /**
     * Someone else's server, over HTTP. On Symfony's client rather than
     * whatever php-http/discovery finds: a transport that depends on which
     * packages happen to be installed works on one machine.
     */
    private function http(): HttpTransport
    {
        $factory = new Psr17Factory();

        return new HttpTransport(
            endpoint: $this->config->url,
            headers: $this->config->headers,
            httpClient: new Psr18Client(HttpClient::create(), $factory, $factory),
            requestFactory: $factory,
            streamFactory: $factory,
            logger: $this->log,
        );
    }

    /**
     * One exception for every way this can go wrong, carrying what the server
     * said about it — which is the part a user can act on.
     */
    private function failure(string $what, SdkException $e): McpException
    {
        $stderr = $this->log->stderr();

        return new McpException(
            "MCP server “{$this->config->name}”: {$what}: {$e->getMessage()}"
            . ($stderr === '' ? '' : "\n" . $stderr),
            previous: $e,
        );
    }

    /**
     * The SDK counts timeouts in whole seconds and refuses zero. Rounding up is
     * the honest direction: a timeout shorter than asked for would cut off work
     * that was still within its budget.
     */
    private function seconds(float $timeout): int
    {
        return max(1, (int) ceil($timeout));
    }

    /**
     * A declared env is added to the one Sherpa runs with, not substituted for
     * it: a server started with an env of exactly `{"TOKEN": "…"}` loses PATH,
     * and then cannot find its own interpreter.
     *
     * @return array<string, string>
     */
    private function inheritedEnv(): array
    {
        $env = [];

        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * Tool results are a list of content blocks. The model reads text, so the
     * rest is named rather than dropped silently — an image nobody mentions
     * looks like a tool that returned nothing.
     *
     * @param array<int, mixed> $content
     */
    private function flatten(array $content): string
    {
        $parts = [];

        foreach ($content as $block) {
            $parts[] = match (true) {
                $block instanceof TextContent     => (string) $block->text,
                $block instanceof ImageContent    => '[image ' . $block->mimeType . ', cannot be shown here]',
                $block instanceof AudioContent    => '[audio ' . $block->mimeType . ', cannot be shown here]',
                $block instanceof EmbeddedResource => $block->resource instanceof TextResourceContents
                    ? $block->resource->text
                    : '[resource ' . $block->resource->uri . ']',
                $block instanceof ResourceLink    => '[resource ' . $block->uri . ']',
                default                           => '',
            };
        }

        return trim(implode("\n", array_filter($parts, fn(string $p) => $p !== '')));
    }

    private function which(string $command): ?string
    {
        if (str_contains($command, '/')) {
            return is_executable($command) ? $command : null;
        }

        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable($dir . '/' . $command)) {
                return $dir . '/' . $command;
            }
        }

        return null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
