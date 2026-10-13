<?php

namespace App\Tool;

use App\Agent\PlanMode;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Permission\Approver;

#[AsTool(
    name: 'plan_ready',
    description: 'In plan mode, present your plan to the user once you have investigated enough: what you will change, where, in what order, and how you will check it. The user approves it — and you then carry it out — or asks for changes.',
    permission: Permission::AUTO,
)]
class PlanReadyTool
{
    public function __construct(
        private readonly PlanMode $planMode,
        // Whoever is in front of the session: the terminal, or an editor.
        private readonly Approver $approver,
    ) {}

    public function __invoke(
        #[Param('The plan, in Markdown: the changes, the files, the order, how they will be checked')] string $plan,
    ): string {
        if (!$this->planMode->isActive()) {
            return 'Not in plan mode: nothing to approve. Go ahead with the work.';
        }

        $approved = $this->approver->approvePlan($plan);

        if ($approved === null) {
            return 'Plan shown. Nobody can approve it here: stay in plan mode, and stop.';
        }

        if (!$approved) {
            return 'The user did not approve the plan yet. Stop here and wait for what they want changed; stay in plan mode.';
        }

        $this->planMode->off();

        return 'The user approved the plan. Plan mode is off: carry it out now, step by step, and check it as the plan says.';
    }
}
