<?php

declare(strict_types=1);

namespace App\Permission;

use App\Agent\Tool\Permission;
use App\Agent\Tool\Provenance;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\ToolDefinition;
use App\Agent\Tool\ToolResult;

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
     * Returns null if the tool call should proceed, or a ToolResult with the
     * refusal/discuss message if it should not.
     */
    public function check(ToolCall $call, ?ToolDefinition $def): ?ToolResult
    {
        if (!$def instanceof ToolDefinition) {
            return null; // tool not found, let Toolbox handle the error
        }

        if ($def->permission !== Permission::DENY && $this->autoApproved()) {
            return null;
        }

        // Memory is read by every later session, in its system prompt. After a
        // web page or an MCP answer, what the model wants kept may be what
        // that text told it to keep: asked every time, never granted.
        if ($call->name === 'memory_remember' && $this->provenance?->tainted() && $def->permission !== Permission::DENY) {
            $reason = "this session has read {$this->provenance->label()}, and a fact is kept for every later session";

            $result = $this->approver->confirm($call, $def, new Offer([], $reason));

            return $this->toToolResult($call, $result);
        }

        // Reading runs unasked, but not a file that usually holds secrets:
        // asked every time, and never granted for longer.
        $secret = $call->name === 'file_read' ? SecretFiles::reason((string) ($call->arguments['path'] ?? '')) : null;
        if ($secret !== null && $def->permission !== Permission::DENY) {
            $result = $this->approver->confirm($call, $def, new Offer([], $secret));

            return $this->toToolResult($call, $result);
        }

        // After what is asked whatever the mode, before what auto mode stops asking.
        if ($def->permission === Permission::CONFIRM && $this->auto?->allows($call) === true) {
            return null;
        }

        return match ($def->permission) {
            Permission::AUTO   => null,
            Permission::DENY   => new ToolResult($call->id, 'Denied by policy.', isError: true),
            Permission::CONFIRM => $call->name === CommandPolicy::TOOL
                ? $this->askAboutCommand($call, $def)
                : $this->askConfirmation($call, $def),
        };
    }

    /**
     * Convert a confirmation result to a proceed signal or a blocked result.
     *
     * Allow choices return null so the call proceeds. Discuss and Deny return
     * an error ToolResult that blocks execution and carries a message back to
     * the agent.
     */
    private function toToolResult(ToolCall $call, ConfirmResult $result): ?ToolResult
    {
        return match ($result->choice) {
            ConfirmChoice::Once, ConfirmChoice::Session, ConfirmChoice::Project => null,
            ConfirmChoice::Discuss => new ToolResult(
                $call->id,
                'The user discussed this action: ' . ($result->message ?? ''),
                isError: true,
            ),
            ConfirmChoice::Deny => new ToolResult($call->id, 'Refused by the user.', isError: true),
        };
    }

    /**
     * A command runs unasked only when grants cover every command in it, and
     * nothing in it is asked about every time. A grant is made for the
     * commands it names, never for the tool.
     */
    private function askAboutCommand(ToolCall $call, ToolDefinition $def): ?ToolResult
    {
        $command = ShellCommand::parse((string) ($call->arguments['command'] ?? ''));
        $grants = [...$this->project->all(), ...$this->session->all()];
        if (CommandPolicy::allows($command, $grants)) {
            return null;
        }

        $onlyOnce = CommandPolicy::onlyOnce($command);
        $grants = $onlyOnce === null ? CommandPolicy::grantsFor($command) : [];
        $sessionOnly = array_any(
            array_map(static fn(string $grant): string => substr($grant, strlen(CommandPolicy::PREFIX)), $grants),
            static fn(string $rule): bool => CommandPolicy::isOneShot($rule),
        );
        $offer = new Offer(
            $grants,
            $onlyOnce,
            $sessionOnly ? 'one-shot command' : null,
        );

        $result = $this->approver->confirm($call, $def, $offer);
        $choice = $result->choice;
        if ($choice === ConfirmChoice::Discuss) {
            return new ToolResult(
                $call->id,
                'The user discussed this action: ' . ($result->message ?? ''),
                isError: true,
            );
        }
        if ($choice === ConfirmChoice::Deny) {
            return new ToolResult($call->id, 'Refused by the user.', isError: true);
        }
        if ($choice === ConfirmChoice::Project && !$offer->persistable()) {
            $choice = ConfirmChoice::Session;
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

        return null;
    }

    /**
     * Ask about a non-command tool call that needs confirmation.
     *
     * If a standing grant already covers the tool, the call proceeds without
     * asking. Otherwise the user is prompted and the answer determines whether
     * the call runs and whether a grant is made.
     */
    private function askConfirmation(ToolCall $call, ToolDefinition $def): ?ToolResult
    {
        // Standing grant from a previous session, or one made in this one?
        if ($this->project->isAllowed($call->name) || $this->session->isSessionAllowed($call->name)) {
            return null;
        }

        $result = $this->approver->confirm($call, $def, new Offer());
        $choice = $result->choice;
        if ($choice === ConfirmChoice::Discuss) {
            return new ToolResult(
                $call->id,
                'The user discussed this action: ' . ($result->message ?? ''),
                isError: true,
            );
        }

        return match ($choice) {
            ConfirmChoice::Once    => null,
            ConfirmChoice::Session => $this->grantForSession($call->name) ? null : null,
            ConfirmChoice::Project => $this->grantForProject($call->name) ? null : null,
            ConfirmChoice::Deny    => new ToolResult($call->id, 'Refused by the user.', isError: true),
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
