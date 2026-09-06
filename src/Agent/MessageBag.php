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

    public function tool(string $name, string $content): void
    {
        $this->messages[] = ['role' => 'tool', 'content' => $content, 'name' => $name];
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
     * Messages that belong to the conversation rather than to Sherpa.
     *
     * The system message is rebuilt on demand from the project, its memory and
     * the skills index, so it is never lost and never counted among what a
     * reset would cost. Counting it would overstate the loss by exactly one,
     * every time — and this number is shown to someone deciding whether to
     * throw the conversation away.
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

    /**
     * Compact old messages: replace messages between index 1 and $keepLast
     * with a single assistant summary, to stay within context budget.
     */
    public function compact(string $summary, int $keepLast = 10): void
    {
        $system = [];
        $rest = [];

        foreach ($this->messages as $msg) {
            if ($msg['role'] === 'system') {
                $system[] = $msg;
            } else {
                $rest[] = $msg;
            }
        }

        $kept = array_slice($rest, -$keepLast);
        $compacted = ['role' => 'assistant', 'content' => "[Résumé de la conversation précédente]\n{$summary}"];

        $this->messages = array_merge($system, [$compacted], $kept);
    }
}