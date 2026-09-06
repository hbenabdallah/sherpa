<?php

namespace App\Mcp;

/**
 * A remote tool, shaped like a local one.
 *
 * Toolbox spreads a model's arguments as named parameters into the handler, so
 * a variadic collects them with their names intact and hands them straight to
 * the server.
 */
final class McpTool
{
    public function __construct(
        private readonly McpClient $client,
        private readonly string $remoteName,
    ) {}

    public function __invoke(mixed ...$arguments): string
    {
        $result = $this->client->callTool($this->remoteName, $arguments);

        // Toolbox turns a thrown exception into a tool result flagged as an
        // error, which is how the model is told the call failed rather than
        // reading a failure as an answer.
        if ($result['isError']) {
            throw new McpException($result['text'] === '' ? 'le serveur a signalé une erreur' : $result['text']);
        }

        return $result['text'] === '' ? '(aucun contenu renvoyé)' : $result['text'];
    }
}
