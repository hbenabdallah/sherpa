<?php

namespace App\Permission;

use App\Agent\Tool\Permission;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;
use App\TUI\ConfirmOverlay;

class PermissionBroker
{
    public function __construct(
        private readonly SessionPermissions $session,
        private readonly ConfirmOverlay $overlay,
        // Required, not nullable-with-default: a nullable argument that silently
        // fails to autowire would put back exactly the bug being fixed here —
        // a "[p] Toujours" that quietly grants nothing. Make it a boot failure.
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
            Permission::CONFIRM => $this->askConfirmation($call, $def),
        };
    }

    private function askConfirmation(ToolCall $call, ToolDefinition $def): bool
    {
        // Standing grant from a previous session, or one made in this one?
        if ($this->project->isAllowed($call->name) || $this->session->isSessionAllowed($call->name)) {
            return true;
        }

        return match ($this->overlay->show($call, $def)) {
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
