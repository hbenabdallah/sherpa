<?php

namespace App\Permission;

/**
 * What answering "this session" or "always" to a question would grant, shown
 * with the question: the tool, or for a command the commands it names. Some
 * calls are only ever allowed once, and then the reason is shown instead.
 */
final class Offer
{
    public function __construct(
        /** @var list<string> the grants, as kept; empty for a grant of the whole tool */
        public readonly array $grants = [],
        /** Why only "once" is offered, or null when a grant may be made. */
        public readonly ?string $onlyOnce = null,
    ) {}

    public function standing(): bool
    {
        return $this->onlyOnce === null;
    }

    /** @return list<string> the commands granted, as written in /permissions without the tool's name */
    public function commands(): array
    {
        return array_map(
            static fn(string $grant): string => str_starts_with($grant, CommandPolicy::PREFIX)
                ? substr($grant, strlen(CommandPolicy::PREFIX))
                : $grant,
            $this->grants,
        );
    }
}
