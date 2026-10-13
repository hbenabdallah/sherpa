<?php

namespace App\Agent;

/**
 * Where a turn is shown as it happens: the reply as it streams, the tools it
 * calls and what they return, and what Sherpa has to say along the way. The
 * terminal is one place (ChatPane); an editor speaking ACP is another.
 */
interface AgentOutput
{
    /** A reply is about to stream. */
    public function beginAssistantMessage(): void;

    public function appendToken(string $token): void;

    /**
     * The reply is over. $wasToolCall: its text turned out to be a tool call
     * written as prose, recovered by AgentLoop, and is not to be shown as text.
     */
    public function flushBuffer(bool $wasToolCall = false): void;

    /**
     * Nothing to show yet, and still waiting.
     *
     * @param string|null $why what is awaited instead of the model — a rate limit
     */
    public function showWaiting(bool $reasoning = false, ?string $why = null): void;

    /** @param array<string, mixed> $args */
    public function showToolCall(string $toolName, array $args = [], ?string $callId = null): void;

    public function showToolResult(string $toolName, string $result, bool $isError = false, ?string $callId = null): void;

    public function showInfo(string $message): void;

    public function showError(string $message): void;

    /** Where a sub-agent started from this output shows its work. */
    public function forSubagent(): AgentOutput;
}
