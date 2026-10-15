<?php

namespace App\Acp;

use App\Agent\AgentOutput;
use App\TUI\Terminal;

/**
 * A sub-agent at work, as an editor sees it: one thought line per tool it
 * uses, and nothing of what it writes — its report goes to the main agent,
 * which is who it is for.
 */
final class SubagentThoughts implements AgentOutput
{
    private string $messageId;

    public function __construct(private readonly Connection $connection, private readonly string $sessionId)
    {
        $this->messageId = 'sub_' . bin2hex(random_bytes(6));
    }

    public function beginAssistantMessage(): void
    {
    }

    public function appendToken(string $token): void
    {
    }

    public function flushBuffer(bool $wasToolCall = false): void
    {
    }

    public function showWaiting(bool $reasoning = false, ?string $why = null): void
    {
    }

    public function showRunning(string $toolName, float $seconds): void
    {
    }

    public function showToolCall(string $toolName, array $args = [], ?string $callId = null): void
    {
        $this->say('↳ ' . ToolDescriptions::title($toolName, $args));
    }

    public function showToolResult(string $toolName, string $result, bool $isError = false, ?string $callId = null): void
    {
    }

    public function showInfo(string $message): void
    {
        $this->say('↳ ' . $message);
    }

    public function showError(string $message): void
    {
        $this->say('↳ ⚠ ' . $message);
    }

    public function forSubagent(): AgentOutput
    {
        return $this;
    }

    private function say(string $line): void
    {
        $this->connection->notify('session/update', ['sessionId' => $this->sessionId, 'update' => [
            'sessionUpdate' => 'agent_thought_chunk',
            'messageId'     => $this->messageId,
            'content'       => ['type' => 'text', 'text' => Terminal::plain($line, singleLine: true) . "\n"],
        ]]);
    }
}
