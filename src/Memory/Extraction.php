<?php

namespace App\Memory;

/**
 * The outcome of one end-of-session extraction pass.
 *
 * The old code printed a count only when everything went right and swallowed
 * every failure, so "nothing worth remembering" and "the model returned
 * garbage" and "Ollama was gone" all looked identical: silence.
 */
final class Extraction
{
    private function __construct(
        public readonly int $created = 0,
        public readonly int $alreadyKnown = 0,
        public readonly int $rejected = 0,
        public readonly ?string $skipped = null,
        public readonly ?string $error = null,
    ) {}

    public static function of(int $created, int $alreadyKnown, int $rejected): self
    {
        return new self(created: $created, alreadyKnown: $alreadyKnown, rejected: $rejected);
    }

    /** Nothing was attempted, and that was the right call. */
    public static function skipped(string $why): self
    {
        return new self(skipped: $why);
    }

    /** Something was attempted and did not work. */
    public static function failed(string $error): self
    {
        return new self(error: $error);
    }

    public function summary(): string
    {
        if ($this->skipped !== null) {
            return $this->skipped;
        }

        if ($this->error !== null) {
            return "Extraction des faits impossible : {$this->error}";
        }

        if ($this->created === 0 && $this->alreadyKnown === 0) {
            return 'Rien de durable à retenir de cette session.';
        }

        $parts = [];
        if ($this->created > 0) {
            $parts[] = $this->created . ' nouveau' . ($this->created > 1 ? 'x faits' : ' fait');
        }
        if ($this->alreadyKnown > 0) {
            $parts[] = $this->alreadyKnown . ' déjà connu' . ($this->alreadyKnown > 1 ? 's' : '');
        }
        if ($this->rejected > 0) {
            $parts[] = $this->rejected . ' rejeté' . ($this->rejected > 1 ? 's' : '');
        }

        return 'Mémoire : ' . implode(', ', $parts) . '.';
    }
}
