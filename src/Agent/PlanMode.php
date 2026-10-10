<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Tool\Permission;
use App\Agent\Tool\ToolDefinition;

/**
 * Plan first, change later: while it is on, the model reads and proposes, and
 * nothing that changes anything runs until the plan is approved.
 *
 * The tools stay in the request — taking some out would change it from the
 * top, and cost the provider's prompt cache and Anthropic's thinking blocks —
 * and a blocked call is answered with why, so the model goes back to planning.
 * What is blocked is what asks before it runs, the web page reader aside: a
 * write, a command, an MCP server's tool.
 */
final class PlanMode
{
    public const BLOCKED = 'Plan mode: nothing is changed until the user approves a plan. Keep investigating, then present the plan with plan_ready.';

    private const REMINDER = "[Plan mode — change nothing yet: read and investigate what this needs, then present your plan with plan_ready. The user approves it before anything is changed.]\n\n";

    private bool $active = false;

    public function on(): void
    {
        $this->active = true;
    }

    public function off(): void
    {
        $this->active = false;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function blocks(?ToolDefinition $def): bool
    {
        return $this->active && $def !== null && $def->permission === Permission::CONFIRM && $def->name !== 'web_fetch';
    }

    /** The request as the model reads it while planning: with the reminder of what plan mode is. */
    public function frame(string $request): string
    {
        return $this->active ? self::REMINDER . $request : $request;
    }

    /** The request as the user typed it, for showing it back: a title, a summary. */
    public static function unframe(string $message): string
    {
        return str_starts_with($message, self::REMINDER) ? substr($message, strlen(self::REMINDER)) : $message;
    }
}
