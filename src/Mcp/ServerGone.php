<?php

namespace App\Mcp;

use Mcp\Exception\ConnectionException;

/**
 * A request could not be written: the server's process is no longer there to
 * read it. Nothing was delivered, so sending it again to a new process is safe.
 */
final class ServerGone extends ConnectionException
{
}
