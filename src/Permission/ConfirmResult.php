<?php

declare(strict_types=1);

namespace App\Permission;

/**
 * The user's answer to a confirmation: which choice and, when discussing,
 * the message sent back to the agent instead of a fixed refusal.
 */
final readonly class ConfirmResult
{
    public function __construct(
        public ConfirmChoice $choice,
        public ?string $message = null,
    ) {}

    public static function once(): self
    {
        return new self(ConfirmChoice::Once);
    }

    public static function session(): self
    {
        return new self(ConfirmChoice::Session);
    }

    public static function project(): self
    {
        return new self(ConfirmChoice::Project);
    }

    public static function deny(): self
    {
        return new self(ConfirmChoice::Deny);
    }

    public static function discuss(string $message): self
    {
        return new self(ConfirmChoice::Discuss, $message);
    }
}
