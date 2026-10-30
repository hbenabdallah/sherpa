<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Tool\Permission;
use App\Agent\Tool\ToolDefinition;

/**
 * The session's mode, one at a time: default, plan or auto. Plan first, change
 * later: while plan mode is on, the model reads and proposes, and nothing that
 * changes anything runs until the plan is approved. What auto mode lets run
 * unasked is AutoMode's to say.
 *
 * The tools stay in the request — taking some out would change it from the
 * top, and cost the provider's prompt cache and Anthropic's thinking blocks —
 * and a blocked call is answered with why, so the model goes back to planning.
 * What is blocked is what asks before it runs, the web page reader aside: a
 * write, a command, an MCP server's tool.
 */
final class PlanMode
{
    public const string BLOCKED = 'Plan mode: nothing is changed until the user approves a plan. Keep investigating, then present the plan with plan_ready.';

    private const string REMINDER = "[Plan mode — change nothing yet: read and investigate what this needs, then present your plan with plan_ready. The user approves it before anything is changed.]\n\n";

    private Mode $mode = Mode::Default;

    public function on(): void
    {
        $this->mode = Mode::Plan;
    }

    /** Leaves plan mode for the default one; auto mode is left as it is. */
    public function off(): void
    {
        if ($this->mode === Mode::Plan) {
            $this->mode = Mode::Default;
        }
    }

    public function isActive(): bool
    {
        return $this->mode === Mode::Plan;
    }

    public function mode(): Mode
    {
        return $this->mode;
    }

    public function switchTo(Mode $mode): void
    {
        $this->mode = $mode;
    }

    public function blocks(?ToolDefinition $def): bool
    {
        return $this->isActive() && $def instanceof ToolDefinition && $def->permission === Permission::CONFIRM && $def->name !== 'web_fetch';
    }

    /** The request as the model reads it while planning: with the reminder of what plan mode is. */
    public function frame(string $request): string
    {
        return $this->isActive() ? self::REMINDER . $request : $request;
    }

    /** The request as the user typed it, for showing it back: a title, a summary. */
    public static function unframe(string $message): string
    {
        return str_starts_with($message, self::REMINDER) ? substr($message, strlen(self::REMINDER)) : $message;
    }
}
