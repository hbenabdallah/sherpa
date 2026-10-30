<?php

namespace App\Project;

use Stringable;

/**
 * What a project is written in, and what it is built out of.
 *
 * The language is a field of its own because the system prompt names it
 * ("a PHP development agent"), and that is the part it has to get right.
 */
final readonly class Stack implements Stringable
{
    /**
     * @param string|null        $language the primary language, on its own —
     *                                     the prompt says "a {language}
     *                                     development agent", and "PHP ~8.4.0"
     *                                     does not fit there
     * @param string|null        $version  the version constraint, kept apart so
     *                                     the summary can show it
     * @param array<int, string> $parts    frameworks, tools, runtimes
     */
    public function __construct(
        public ?string $language = null,
        public ?string $version = null,
        public array $parts = [],
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
            : trim($this->language . ' ' . $this->version);

        $all = array_values(array_filter(
            array_merge($language === null ? [] : [$language], $this->parts),
            fn(string $p): bool => trim($p) !== '',
        ));

        return $all === [] ? 'Unknown' : implode(', ', $all);
    }

    public function __toString(): string
    {
        return $this->summary();
    }
}
