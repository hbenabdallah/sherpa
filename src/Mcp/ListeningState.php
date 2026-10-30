<?php

namespace App\Mcp;

use App\Runtime\Interrupt;
use Mcp\Client\State\ClientStateInterface;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;

/**
 * The SDK's record of what is awaited, as a transport sees it. The transport
 * asks it for an answer every millisecond while a request is out — a tool
 * that runs for two minutes asks a hundred thousand times — and that is where
 * Ctrl+C is heard and the "running" line kept turning: a cancel answers the
 * request with an error, and the call comes back at once.
 *
 * The server is not told; it finishes, and its late answer goes nowhere.
 */
final readonly class ListeningState implements ClientStateInterface
{
    public const string INTERRUPTED = 'Interrupted by the user.';

    public function __construct(
        private ClientStateInterface $state,
        private Interrupt $interrupt,
    ) {}

    public function consumeResponse(int|string $requestId): Response|Error|null
    {
        $response = $this->state->consumeResponse($requestId);
        if ($response === null && $this->interrupt->requested()) {
            return Error::forInternalError(self::INTERRUPTED, $requestId);
        }

        return $response;
    }

    public function nextRequestId(): int
    {
        return $this->state->nextRequestId();
    }

    public function addPendingRequest(int|string $requestId, int $timeout): void
    {
        $this->state->addPendingRequest($requestId, $timeout);
    }

    public function removePendingRequest(int|string $requestId): void
    {
        $this->state->removePendingRequest($requestId);
    }

    public function getPendingRequests(): array
    {
        return $this->state->getPendingRequests();
    }

    public function storeResponse(int|string $requestId, array $responseData): void
    {
        $this->state->storeResponse($requestId, $responseData);
    }

    public function setInitialized(bool $initialized): void
    {
        $this->state->setInitialized($initialized);
    }

    public function isInitialized(): bool
    {
        return $this->state->isInitialized();
    }

    public function setProtocolVersion(ProtocolVersion $protocolVersion): void
    {
        $this->state->setProtocolVersion($protocolVersion);
    }

    public function getProtocolVersion(): ?ProtocolVersion
    {
        return $this->state->getProtocolVersion();
    }

    public function setServerInfo(Implementation $serverInfo): void
    {
        $this->state->setServerInfo($serverInfo);
    }

    public function getServerInfo(): ?Implementation
    {
        return $this->state->getServerInfo();
    }

    public function setInstructions(?string $instructions): void
    {
        $this->state->setInstructions($instructions);
    }

    public function getInstructions(): ?string
    {
        return $this->state->getInstructions();
    }

    public function storeProgress(string $token, float $progress, ?float $total, ?string $message): void
    {
        $this->state->storeProgress($token, $progress, $total, $message);
    }

    public function consumeProgressUpdates(): array
    {
        return $this->state->consumeProgressUpdates();
    }
}
