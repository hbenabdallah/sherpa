<?php

declare(strict_types=1);

namespace App\Memory;

use App\Agent\MessageBag;

/**
 * Where the fact pass got to in a conversation, so the next pass reads only
 * what came after. Held as the last message read, by its content as well as
 * its place: compaction folds old turns into a summary, /reset and /resume
 * swap the whole history, and each moves the place. Not found, the next pass
 * starts from the top again: reading twice costs a request, skipping loses
 * facts.
 */
final class FactCursor
{
    private int $index = -1;

    private ?string $anchor = null;

    /** The first message no pass has read yet. */
    public function from(MessageBag $bag): int
    {
        if ($this->anchor === null) {
            return 0;
        }

        $messages = $bag->all();

        // Compaction only ever shortens what came before, so the anchor is at
        // its old place or below it.
        for ($i = min($this->index, count($messages) - 1); $i >= 0; $i--) {
            if (self::fingerprint($messages[$i]) === $this->anchor) {
                return $i + 1;
            }
        }

        return 0;
    }

    /** Everything in $bag now counts as read. */
    public function advance(MessageBag $bag): void
    {
        $messages = $bag->all();

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $fingerprint = self::fingerprint($messages[$i]);
            if ($fingerprint !== null) {
                $this->index = $i;
                $this->anchor = $fingerprint;

                return;
            }
        }
    }

    /**
     * Null for a message with no words in it: an assistant message that only
     * calls tools has none, and two of them look alike, so it is no anchor.
     *
     * @param array<string, mixed> $message
     */
    private static function fingerprint(array $message): ?string
    {
        $role = $message['role'] ?? null;
        $content = $message['content'] ?? null;

        if (!in_array($role, ['user', 'assistant'], true) || !is_string($content) || trim($content) === '') {
            return null;
        }

        return hash('xxh128', $role . "\0" . $content);
    }
}
