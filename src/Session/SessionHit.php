<?php

declare(strict_types=1);

namespace App\Session;

/** One message of an earlier conversation that matches a search, cut to where it does. */
final readonly class SessionHit
{
    public function __construct(
        public SavedSession $session,
        /** 'user' or 'assistant'. */
        public string $role,
        public string $excerpt,
        /** The start of the answer that followed, when the hit is a question. */
        public ?string $reply = null,
    ) {}
}
