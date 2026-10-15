<?php

namespace App\Mcp;

use Mcp\Client\Transport\HttpTransport;

/** The SDK's HTTP transport, its waits heard by Ctrl+C. */
final class RemoteServer extends HttpTransport
{
    use Listens;
}
