<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * A piece of a document, as it is searched and as it is shown.
 *
 * Two texts, on purpose. $text is small and precise, and is what the search
 * matches against: a short passage about one thing ranks for that thing. What
 * the model receives is $parent — the whole section, or the passage with what
 * surrounds it — because an answer read out of its context is how a model
 * gets it wrong. Small to find, large to read.
 */
final class Chunk
{
    /** @param list<string> $headings */
    public function __construct(
        public readonly string $path,
        public readonly string $title,
        public readonly array $headings,
        public readonly string $text,
        public readonly string $parent,
        public readonly int $startLine,
        public readonly int $endLine,
        public readonly int $position,
    ) {}

    /**
     * Where the passage sits, in words: "Guide › Refunds › Times".
     *
     * Indexed with the passage — a cheap, deterministic form of what is called
     * contextual retrieval. "Within 30 days" alone is not findable by
     * "how long does a refund take"; under that path, it is.
     */
    public function context(): string
    {
        $path = $this->headings;
        if ($path !== [] && $path[0] === $this->title) {
            array_shift($path);
        }

        return implode(' › ', [$this->title, ...$path]);
    }

    /** "docs/refunds.md › Times (l. 12-40)": what the model cites. */
    public function source(): string
    {
        $section = $this->headings === [] ? '' : ' › ' . implode(' › ', $this->headings);

        return "{$this->path}{$section} (l. {$this->startLine}-{$this->endLine})";
    }
}
