<?php

namespace App\Mcp;

/**
 * A server's resources, read by URI: one tool per server, whose description
 * lists what there is to read.
 */
final readonly class McpResourceReader
{
    public function __construct(private McpClient $client) {}

    public function __invoke(string $uri): string
    {
        $text = $this->client->readResource($uri);

        return $text === '' ? '(empty resource)' : $text;
    }
}
