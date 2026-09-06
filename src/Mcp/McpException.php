<?php

namespace App\Mcp;

/**
 * Anything that goes wrong talking to an MCP server.
 *
 * These are third-party processes: they crash, they hang, they answer in a
 * shape nobody promised. None of that is allowed to take Sherpa down with it,
 * so every failure arrives here and is dealt with at the boundary.
 */
class McpException extends \RuntimeException
{
}
