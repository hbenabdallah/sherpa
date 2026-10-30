<?php

declare(strict_types=1);

namespace App\Permission;

use App\Agent\Mode;
use App\Agent\PlanMode;
use App\Agent\Tool\ToolCall;

/**
 * What auto mode runs without asking: a file written or patched — the file
 * tools never leave the project — and a command the sandbox or the project's
 * container holds in. Nothing else changes: a command asked about every time
 * still is (sudo, rm -r, git push, a secret named, one that cannot be read),
 * and so are a file that holds secrets, a web page, an MCP server's tool and a
 * memory written after untrusted text. PermissionBroker asks those first.
 */
final readonly class AutoMode
{
    private const array EDITS = ['file_write', 'file_patch'];

    public function __construct(
        private PlanMode $mode,
        private CommandConfinement $commands,
    ) {}

    public function isOn(): bool
    {
        return $this->mode->mode() === Mode::Auto;
    }

    public function allows(ToolCall $call): bool
    {
        if (!$this->isOn()) {
            return false;
        }

        if (in_array($call->name, self::EDITS, true)) {
            return true;
        }

        if ($call->name !== CommandPolicy::TOOL || $this->commands->confinedBy() === null) {
            return false;
        }

        $command = ShellCommand::parse((string) ($call->arguments['command'] ?? ''));

        return CommandPolicy::onlyOnce($command) === null;
    }

    /** What auto mode lets run here, and what it still asks about: said when it is turned on. */
    public function describe(): string
    {
        $confinedBy = $this->commands->confinedBy();
        $unasked = $confinedBy === null
            ? 'Edits in the project run without asking. Commands still ask: no sandbox or container holds them in here.'
            : "Edits in the project, and commands held in {$confinedBy}, run without asking.";

        return $unasked . ' Still asked every time: sudo, rm -r, git push and the like, files that hold secrets, web pages and MCP tools.';
    }
}
