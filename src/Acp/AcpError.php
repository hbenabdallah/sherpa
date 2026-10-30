<?php

namespace App\Acp;

use RuntimeException;

/**
 * A JSON-RPC error: sent back for a request that failed, or received for one
 * Sherpa sent. The codes are JSON-RPC's, and ACP's own on top of them.
 */
final class AcpError extends RuntimeException
{
    public const PARSE_ERROR = -32700;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const INVALID_PARAMS = -32602;

    public const INTERNAL_ERROR = -32603;

    public const AUTH_REQUIRED = -32000;

    public const RESOURCE_NOT_FOUND = -32002;

    public const CANCELLED = -32800;

    public function __construct(string $message, int $code = self::INTERNAL_ERROR, public readonly mixed $data = null)
    {
        parent::__construct($message, $code);
    }

    public static function invalidParams(string $message): self
    {
        return new self($message, self::INVALID_PARAMS);
    }
}
