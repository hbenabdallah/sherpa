<?php

namespace App\Runtime;

use App\Agent\AgentOutput;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;
use App\Permission\Approver;
use App\Permission\ConfirmChoice;
use App\Permission\Offer;
use App\TUI\ChatPane;
use App\TUI\ConfirmOverlay;

/**
 * Who the session is in front of: the terminal unless `sherpa acp` hands it
 * to an editor. Everything that shows a turn or asks a question is given this
 * one instance, as SwitchablePlatform is given for the model: nothing else
 * needs to know there are two.
 */
class Frontend implements AgentOutput, Approver
{
    private AgentOutput $output;
    private Approver $approver;

    public function __construct(ChatPane $chat, ConfirmOverlay $overlay)
    {
        $this->output = $chat;
        $this->approver = $overlay;
    }

    public function use(AgentOutput $output, Approver $approver): void
    {
        $this->output = $output;
        $this->approver = $approver;
    }

    public function beginAssistantMessage(): void
    {
        $this->output->beginAssistantMessage();
    }

    public function appendToken(string $token): void
    {
        $this->output->appendToken($token);
    }

    public function flushBuffer(bool $wasToolCall = false): void
    {
        $this->output->flushBuffer($wasToolCall);
    }

    public function showWaiting(bool $reasoning = false, ?string $why = null): void
    {
        $this->output->showWaiting($reasoning, $why);
    }

    public function showRunning(string $toolName, float $seconds): void
    {
        $this->output->showRunning($toolName, $seconds);
    }

    public function showToolCall(string $toolName, array $args = [], ?string $callId = null): void
    {
        $this->output->showToolCall($toolName, $args, $callId);
    }

    public function showToolResult(string $toolName, string $result, bool $isError = false, ?string $callId = null): void
    {
        $this->output->showToolResult($toolName, $result, $isError, $callId);
    }

    public function showInfo(string $message): void
    {
        $this->output->showInfo($message);
    }

    public function showError(string $message): void
    {
        $this->output->showError($message);
    }

    public function forSubagent(): AgentOutput
    {
        return $this->output->forSubagent();
    }

    public function confirm(ToolCall $call, ToolDefinition $def, Offer $offer): ConfirmChoice
    {
        return $this->approver->confirm($call, $def, $offer);
    }

    public function approvePlan(string $plan): ?bool
    {
        return $this->approver->approvePlan($plan);
    }
}
