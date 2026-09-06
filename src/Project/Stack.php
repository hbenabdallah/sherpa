<?php

namespace App\Project;

/**
 * What a project is written in, and what it is built out of.
 *
 * The detector used to return one flat string, which was enough to print but
 * not enough to reason with: the system prompt introduced Sherpa as "un agent
 * de développement PHP/Symfony" no matter what it was looking at. The language
 * is now a field, because that is the part the prompt has to get right.
 */
final class Stack
{
    /**
     * @param string|null        $language the primary language, on its own —
     *                                     the prompt says "un agent de
     *                                     développement {language}", and
     *                                     "PHP ~8.4.0" does not fit there
     * @param string|null        $version  the version constraint, kept apart so
     *                                     the summary can show it
     * @param array<int, string> $parts    frameworks, tools, runtimes
     */
    public function __construct(
        public readonly ?string $language = null,
        public readonly ?string $version = null,
        public readonly array $parts = [],
    ) {}

    public static function unknown(): self
    {
        return new self();
    }

    public function isKnown(): bool
    {
        return $this->language !== null || $this->parts !== [];
    }

    /** The one-line form, which is what gets stored and shown. */
    public function summary(): string
    {
        $language = $this->language === null
            ? null
            : trim($this->language . ' ' . (string) $this->version);

        $all = array_values(array_filter(
            array_merge($language === null ? [] : [$language], $this->parts),
            fn(string $p) => trim($p) !== '',
        ));

        return $all === [] ? 'Unknown' : implode(', ', $all);
    }

    public function __toString(): string
    {
        return $this->summary();
    }
}
