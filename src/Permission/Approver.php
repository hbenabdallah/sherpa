<?php

declare(strict_types=1);

namespace App\Permission;

use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;

/**
 * Whoever answers Sherpa's questions: the person at the terminal
 * (ConfirmOverlay), or an editor speaking ACP on their behalf.
 */
interface Approver
{
    /**
     * Whether a tool call that needs confirmation may run, and for how long the
     * answer holds. $offer says what a longer answer would grant, or that only
     * "once" may be given.
     */
    public function confirm(ToolCall $call, ToolDefinition $def, Offer $offer): ConfirmChoice;

    /**
     * Show a plan made in plan mode and ask whether to carry it out.
     *
     * @return bool|null null when nobody can answer here
     */
    public function approvePlan(string $plan): ?bool;
}
