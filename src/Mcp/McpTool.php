<?php

declare(strict_types=1);

namespace App\Mcp;

use InvalidArgumentException;

/**
 * A remote tool, shaped like a local one.
 *
 * Toolbox spreads a model's arguments as named parameters into the handler, so
 * a variadic collects them with their names intact and hands them straight to
 * the server.
 */
final readonly class McpTool
{
    public function __construct(
        private McpClient $client,
        private string $remoteName,
    ) {}

    public function __invoke(mixed ...$arguments): string
    {
        $named = [];
        foreach ($arguments as $name => $value) {
            // MCP sends arguments as a JSON object: a position has no name to send.
            if (is_int($name)) {
                throw new InvalidArgumentException("MCP tool {$this->remoteName} takes its arguments by name, got one at position {$name}");
            }
            $named[$name] = $value;
        }

        $result = $this->client->callTool($this->remoteName, $named);

        // Toolbox turns a thrown exception into a tool result flagged as an
        // error, which is how the model is told the call failed rather than
        // reading a failure as an answer.
        if ($result['isError']) {
            throw new McpException($result['text'] === '' ? 'the server reported an error' : $result['text']);
        }

        return $result['text'] === '' ? '(no content returned)' : $result['text'];
    }
}
