<?php

declare(strict_types=1);

namespace App\Rag;

/** What a sync did, counted — so /docs can say it, and a test can check it. */
final class SyncReport
{
    public int $added = 0;
    public int $updated = 0;
    public int $removed = 0;
    public int $unchanged = 0;

    /** @var array<string, string> path => why it could not be indexed */
    public array $failed = [];

    /** Chunks given a vector during this sync. */
    public int $embedded = 0;

    /** Why the vectors could not be made, when they could not. */
    public ?string $embeddingError = null;

    public function changed(): bool
    {
        return $this->added + $this->updated + $this->removed > 0;
    }

    public function summary(): string
    {
        $parts = [];
        foreach (['added' => 'added', 'updated' => 'updated', 'removed' => 'removed'] as $field => $word) {
            if ($this->{$field} > 0) {
                $parts[] = $this->{$field} . ' ' . $word;
            }
        }

        $line = $parts === [] ? 'nothing changed' : implode(', ', $parts);
        if ($this->embedded > 0) {
            $line .= ' · ' . $this->embedded . ' passage' . ($this->embedded > 1 ? 's' : '') . ' embedded';
        }
        if ($this->failed !== []) {
            $line .= ' · ' . count($this->failed) . ' unreadable';
        }

        return $line;
    }
}
