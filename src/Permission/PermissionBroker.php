<?php

declare(strict_types=1);

namespace App\Permission;

use App\Agent\Tool\Permission;
use App\Agent\Tool\Provenance;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;

/** @open the suites extend it with brokers that answer for the user */
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
        // What the session read from outside: a fact written after it is asked about.
        private readonly ?Provenance $provenance = null,
        // SHERPA_AUTO_APPROVE when not given; see autoApproved().
        private readonly ?bool $autoApprove = null,
        // Auto mode, when the session can be put in it.
        private readonly ?AutoMode $auto = null,
    ) {}

    /**
     * Whether every question is answered "once" without being asked: for a
     * run nobody watches — the benchmark, a CI job — where the old standing
     * grant of shell_exec used to do it. Grants nothing that outlives the
     * call. Never on unless set, and said in red at start.
     */
    public function autoApproved(): bool
    {
        return $this->autoApprove ?? getenv('SHERPA_AUTO_APPROVE') === '1';
    }

    /**
     * Returns true if the tool call should proceed.
     */
    public function check(ToolCall $call, ?ToolDefinition $def): bool
    {
        if (!$def instanceof ToolDefinition) {
            return true; // tool not found, let Toolbox handle the error
        }

        if ($def->permission !== Permission::DENY && $this->autoApproved()) {
            return true;
        }

        // Memory is read by every later session, in its system prompt. After a
        // web page or an MCP answer, what the model wants kept may be what
        // that text told it to keep: asked every time, never granted.
        if ($call->name === 'memory_remember' && $this->provenance?->tainted() && $def->permission !== Permission::DENY) {
            $reason = "this session has read {$this->provenance->label()}, and a fact is kept for every later session";

            return $this->approver->confirm($call, $def, new Offer([], $reason)) !== ConfirmChoice::Deny;
        }

        // Reading runs unasked, but not a file that usually holds secrets:
        // asked every time, and never granted for longer.
        $secret = $call->name === 'file_read' ? SecretFiles::reason((string) ($call->arguments['path'] ?? '')) : null;
        if ($secret !== null && $def->permission !== Permission::DENY) {
            return $this->approver->confirm($call, $def, new Offer([], $secret)) !== ConfirmChoice::Deny;
        }

        // After what is asked whatever the mode, before what auto mode stops asking.
        if ($def->permission === Permission::CONFIRM && $this->auto?->allows($call) === true) {
            return true;
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
