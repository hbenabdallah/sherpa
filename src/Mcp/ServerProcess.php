<?php

namespace App\Mcp;

use Mcp\Client\Transport\StdioTransport;

/**
 * The SDK's stdio transport, told what to do when the process is gone.
 *
 * A server can exit in the middle of a session — it crashed, it was killed, it
 * restarts itself when its own code changes. The SDK never looks: the next
 * request is written to a pipe nobody reads, PHP prints a "Broken pipe" notice
 * over the user's screen, and the call then waits out its whole timeout for an
 * answer that cannot come. Here the failed write is the news, and it arrives as
 * ServerGone, at once.
 */
final class ServerProcess extends StdioTransport
{
    public function send(string $data): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ServerGone('the server process has exited (' . $message . ')');
        });

        try {
            parent::send($data);
        } finally {
            restore_error_handler();
        }
    }
}
