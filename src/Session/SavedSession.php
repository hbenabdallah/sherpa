<?php

declare(strict_types=1);

namespace App\Session;

/**
 * One conversation as it was left: what was said, and what compaction had put
 * aside.
 *
 * No system message: it is rebuilt from the project, its memory and its skills
 * every time a session opens, so storing it would only keep a stale copy of
 * something that is regenerated anyway.
 */
final class SavedSession
{
    /**
     * @param list<array<string, mixed>>                                                   $messages
     * @param list<array{id: int, tool: string, content: string, created_at: string}>      $excerpts
     */
    public function __construct(
        public readonly string $id,
        public readonly \DateTimeImmutable $startedAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly string $backend,
        public readonly string $model,
        public readonly string $title,
        public readonly array $messages,
        public readonly array $excerpts = [],
    ) {}

    /** How many messages a resume brings back — what the user is choosing between. */
    public function size(): int
    {
        return count($this->messages);
    }

    /** The last thing the user asked, to say where the conversation stopped. */
    public function lastQuestion(): string
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if (($this->messages[$i]['role'] ?? '') === 'user') {
                return (string) ($this->messages[$i]['content'] ?? '');
            }
        }

        return '';
    }

    /** The last answer the model gave in words, not in tool calls. */
    public function lastAnswer(): string
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            $message = $this->messages[$i];
            if (($message['role'] ?? '') === 'assistant' && trim((string) ($message['content'] ?? '')) !== '') {
                return (string) $message['content'];
            }
        }

        return '';
    }
}
