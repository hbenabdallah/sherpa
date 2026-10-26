<?php

namespace App\Permission;

use App\Agent\Tool\Permission;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;

class PermissionBroker
{
    public function __construct(
        private readonly SessionPermissions $session,
        // The terminal's overlay, or an editor over ACP.
        private readonly Approver $approver,
        // Required, not nullable-with-default: a nullable argument that silently
        // fails to autowire would put back exactly the bug being fixed here —
        // an "[p] Always" that quietly grants nothing. Make it a boot failure.
        private readonly ProjectPermissions $project,
    ) {}

    /**
     * Returns true if the tool call should proceed.
     */
    public function check(ToolCall $call, ?ToolDefinition $def): bool
    {
        if ($def === null) {
            return true; // tool not found, let Toolbox handle the error
        }

        return match ($def->permission) {
            Permission::AUTO   => true,
            Permission::DENY   => false,
            Permission::CONFIRM => $call->name === CommandPolicy::TOOL
                ? $this->askAboutCommand($call, $def)
                : $this->askConfirmation($call, $def),
        };
    }

    /**
     * A command runs unasked only when grants cover every command in it, and
     * nothing in it is asked about every time. A grant is made for the
     * commands it names, never for the tool.
     */
    private function askAboutCommand(ToolCall $call, ToolDefinition $def): bool
    {
        $command = ShellCommand::parse((string) ($call->arguments['command'] ?? ''));
        $grants = [...$this->project->all(), ...$this->session->all()];
        if (CommandPolicy::allows($command, $grants)) {
            return true;
        }

        $onlyOnce = CommandPolicy::onlyOnce($command);
        $offer = new Offer($onlyOnce === null ? CommandPolicy::grantsFor($command) : [], $onlyOnce);

        $choice = $this->approver->confirm($call, $def, $offer);
        if ($choice === ConfirmChoice::Deny) {
            return false;
        }
        // Whatever an approver answers for a call that can only be allowed
        // once, nothing outlives it.
        if ($onlyOnce === null) {
            foreach ($offer->grants as $grant) {
                match ($choice) {
                    ConfirmChoice::Session => $this->grantForSession($grant),
                    ConfirmChoice::Project => $this->grantForProject($grant),
                    ConfirmChoice::Once    => null,
                };
            }
        }

        return true;
    }

    private function askConfirmation(ToolCall $call, ToolDefinition $def): bool
    {
        // Standing grant from a previous session, or one made in this one?
        if ($this->project->isAllowed($call->name) || $this->session->isSessionAllowed($call->name)) {
            return true;
        }

        return match ($this->approver->confirm($call, $def, new Offer())) {
            ConfirmChoice::Once    => true,
            ConfirmChoice::Session => $this->grantForSession($call->name),
            ConfirmChoice::Project => $this->grantForProject($call->name),
            ConfirmChoice::Deny    => false,
        };
    }

    private function grantForSession(string $toolName): bool
    {
        $this->session->allowForSession($toolName);

        return true;
    }

    /**
     * Held for the session as well as persisted: with no project bound there is
     * nowhere to write to, and someone who answered "always" must not be asked
     * again for the rest of the session regardless.
     */
    private function grantForProject(string $toolName): bool
    {
        $this->project->allow($toolName);
        $this->session->allowForSession($toolName);

        return true;
    }
}
