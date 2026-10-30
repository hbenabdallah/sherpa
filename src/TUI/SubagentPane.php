<?php

declare(strict_types=1);

namespace App\TUI;

use App\Agent\AgentOutput;
use App\Agent\Lanes;

/**
 * What a sub-agent shows while it works: one short line per tool it uses,
 * indented under the call that started it, and nothing of what it writes —
 * its report comes back to the main agent, which is who it is for.
 */
final class SubagentPane extends ChatPane
{
    private const float SPINNER_EVERY = 0.12;

    private float $lastFrame = 0.0;

    private bool $spinning = false;

    public function beginAssistantMessage(): void
    {
        $this->showWaiting();
    }

    public function appendToken(string $token): void {}

    public function flushBuffer(bool $wasToolCall = false): void
    {
        $this->stopSpinning();
    }

    public function showWaiting(bool $reasoning = false, ?string $why = null): void
    {
        if (microtime(true) - $this->lastFrame < self::SPINNER_EVERY) {
            return;
        }
        $this->lastFrame = microtime(true);
        $this->spinning = true;
        $this->showSpinner(Terminal::GRAY . '    ↳ sub-agent at work…' . Terminal::RESET);
    }

    public function showToolCall(string $toolName, array $args = [], ?string $callId = null): void
    {
        $this->stopSpinning();

        $first = $args === [] ? '' : (string) (is_string(reset($args)) ? reset($args) : json_encode(reset($args), JSON_UNESCAPED_UNICODE));
        echo Terminal::GRAY . '    ↳ ' . Lanes::prefix() . Terminal::plain($toolName, singleLine: true)
            . ($first === '' ? '' : ' ' . Terminal::plain(mb_strimwidth($first, 0, 60, '…'), singleLine: true))
            . Terminal::RESET . "\n";
    }

    public function showToolResult(string $toolName, string $result, bool $isError = false, ?string $callId = null): void {}

    public function showPlan(string $plan): void {}

    public function showInfo(string $message): void
    {
        $this->stopSpinning();
        echo Terminal::GRAY . '    ↳ ' . Lanes::prefix() . Terminal::plain($message, singleLine: true) . Terminal::RESET . "\n";
    }

    public function showError(string $message): void
    {
        $this->stopSpinning();
        echo Terminal::YELLOW . '    ↳ ' . Lanes::prefix() . Terminal::plain($message, singleLine: true) . Terminal::RESET . "\n";
    }

    public function forSubagent(): AgentOutput
    {
        return $this;
    }

    private function stopSpinning(): void
    {
        if ($this->spinning) {
            $this->clearSpinner();
            $this->spinning = false;
        }
    }
}
