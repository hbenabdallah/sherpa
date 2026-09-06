<?php

namespace App\Tool;

use App\Agent\PlanMode;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\TUI\MarkdownRenderer;
use App\TUI\Terminal;

#[AsTool(
    name: 'plan_ready',
    description: 'In plan mode, present your plan to the user once you have investigated enough: what you will change, where, in what order, and how you will check it. The user approves it — and you then carry it out — or asks for changes.',
    permission: Permission::AUTO,
)]
class PlanReadyTool
{
    public function __construct(
        private readonly PlanMode $planMode,
        private readonly Terminal $terminal = new Terminal(),
        private readonly MarkdownRenderer $markdown = new MarkdownRenderer(),
        // The keyboard, unless a test answers instead.
        private readonly ?\Closure $answer = null,
    ) {}

    public function __invoke(
        #[Param('The plan, in Markdown: the changes, the files, the order, how they will be checked')] string $plan,
    ): string {
        if (!$this->planMode->isActive()) {
            return 'Not in plan mode: nothing to approve. Go ahead with the work.';
        }

        echo "\n" . Terminal::BOLD . Terminal::LIME . 'Plan' . Terminal::RESET . "\n" . $this->markdown->render(trim($plan)) . "\n\n";

        if ($this->answer === null && (!function_exists('posix_isatty') || !posix_isatty(STDIN))) {
            return 'Plan shown. Nobody can approve it here: stay in plan mode, and stop.';
        }

        $approved = $this->answer !== null ? (bool) ($this->answer)() : $this->ask();
        echo "\r\e[2K" . ($approved
            ? Terminal::GREEN . '  ✓ Plan approved: carrying it out.'
            : Terminal::GRAY . '  Plan not approved: say what to change.') . Terminal::RESET . "\n";

        if (!$approved) {
            return 'The user did not approve the plan yet. Stop here and wait for what they want changed; stay in plan mode.';
        }

        $this->planMode->off();

        return 'The user approved the plan. Plan mode is off: carry it out now, step by step, and check it as the plan says.';
    }

    private function ask(): bool
    {
        $this->terminal->rawMode();
        // A key typed while the plan was being written is no answer to it.
        $this->terminal->discardPendingInput();

        try {
            while (true) {
                echo "\r\e[2K  Carry out this plan? "
                    . Terminal::GREEN . '[y]' . Terminal::RESET . ' yes, go  '
                    . Terminal::YELLOW . '[n]' . Terminal::RESET . ' no, keep planning ';

                $key = strtolower($this->terminal->readKey());
                if (in_array($key, ['y', "\r", "\n"], true)) {
                    return true;
                }
                if (in_array($key, ['n', "\e", ''], true)) {
                    return false;
                }
            }
        } finally {
            $this->terminal->restoreMode();
        }
    }
}
