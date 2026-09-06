<?php

namespace App\Agent;

class MessageBag
{
    private array $messages = [];

    public function system(string $content): void
    {
        // Replace existing system message
        foreach ($this->messages as $i => $msg) {
            if ($msg['role'] === 'system') {
                $this->messages[$i]['content'] = $content;
                return;
            }
        }
        array_unshift($this->messages, ['role' => 'system', 'content' => $content]);
    }

    public function user(string $content): void
    {
        $this->messages[] = ['role' => 'user', 'content' => $content];
    }

    public function assistant(string $content, ?array $toolCalls = null): void
    {
        $msg = ['role' => 'assistant', 'content' => $content];
        if ($toolCalls !== null) {
            $msg['tool_calls'] = $toolCalls;
        }
        $this->messages[] = $msg;
    }

    /**
     * The result of one tool call. $callId ties it to the call it answers:
     * Ollama never needed that, but a hosted API refuses a result that does not
     * name its call, on every request after. Optional only for fixtures.
     */
    public function tool(string $name, string $content, ?string $callId = null): void
    {
        $message = ['role' => 'tool', 'content' => $content, 'name' => $name];
        if ($callId !== null) {
            $message['tool_call_id'] = $callId;
        }
        $this->messages[] = $message;
    }

    /**
     * Answer, with $stub, every call of the latest tool-calling message left
     * without a result. The loop writes the assistant message before running
     * its calls, so a turn that dies in between leaves orphans — which a hosted
     * API rejects on every later request, until /reset. Calls with no id are
     * left alone; the loop always assigns one.
     *
     * @return int how many calls were closed
     */
    public function closeOpenToolCalls(string $stub): int
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if (!empty($this->messages[$i]['tool_calls'])) {
                break;
            }
        }

        if ($i < 0) {
            return 0;
        }

        $answered = array_column(array_slice($this->messages, $i + 1), 'tool_call_id');
        $closed = 0;

        foreach ($this->messages[$i]['tool_calls'] as $call) {
            $id = $call['id'] ?? null;
            if (!is_string($id) || in_array($id, $answered, true)) {
                continue;
            }

            $this->tool((string) ($call['function']['name'] ?? 'tool'), $stub, $id);
            $closed++;
        }

        return $closed;
    }

    public function add(array $message): void
    {
        $this->messages[] = $message;
    }

    public function all(): array
    {
        return $this->messages;
    }

    /**
     * Wholesale replacement, used by HistoryCompactor once it has rewritten the
     * history to fit the context window.
     */
    public function replace(array $messages): void
    {
        $this->messages = array_values($messages);
    }

    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * Messages that belong to the conversation rather than to Sherpa. The
     * system message is rebuilt on demand, so counting it would overstate by
     * one what a reset costs — and this number is shown to someone deciding.
     */
    public function conversationSize(): int
    {
        return count(array_filter(
            $this->messages,
            static fn(array $message) => ($message['role'] ?? '') !== 'system',
        ));
    }

    public function getTranscript(): string
    {
        $lines = [];
        foreach ($this->messages as $msg) {
            $role = strtoupper($msg['role']);
            $content = $msg['content'] ?? '';
            $lines[] = "[{$role}] {$content}";
        }
        return implode("\n\n", $lines);
    }
}