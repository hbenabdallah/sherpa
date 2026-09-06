<?php

namespace App\Tool;

use App\Agent\AgentLoop;
use App\Agent\ContextBudget;
use App\Agent\HistoryCompactor;
use App\Agent\InferenceSpeed;
use App\Agent\MessageBag;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Permission\PermissionBroker;
use App\Platform\PlatformInterface;
use App\Project\ProjectPathResolver;
use App\Runtime\Interrupt;
use App\TUI\MarkdownRenderer;
use App\TUI\SubagentPane;
use App\TUI\Terminal;

#[AsTool(
    name: 'delegate',
    description: 'Hand a question that needs a lot of reading — "where is X handled, and how", "what calls this", "review this module for Y" — to a sub-agent. It searches and reads in a context of its own, which it then throws away, and reports back only its findings, with file:line references. It can read, not change anything. Give it a self-contained task: it does not see this conversation.',
    // It reads, as the tools it is given do, and costs what its requests cost.
    permission: Permission::AUTO,
)]
class DelegateTool
{
    /** What a sub-agent is: the tools that read, and nothing that changes or leaves the machine. */
    private const SYSTEM = <<<'PROMPT'
You are a research sub-agent working for another agent, inside a software project.
You get one task. Investigate it with your tools — search, list, read — then answer.

You can only read: nothing you do changes a file or runs a command.
The agent that asked sees nothing of your work but your final answer, so make it
complete and self-contained: what you found, where (path:line), and what you
could not establish. Be concise; no preamble. Do not ask questions back: decide
what the task most likely means, and say what you assumed.

Project root: %s
PROMPT;

    /** A report longer than this is cut: it lands in the main agent's window. */
    private const MAX_REPORT_CHARS = 12_000;

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly PermissionBroker $permissions,
        private readonly Interrupt $interrupt,
        private readonly ProjectPathResolver $paths,
        private readonly ListDirTool $listDir,
        private readonly FileFindTool $fileFind,
        private readonly ProjectGrepTool $grep,
        private readonly FileReadTool $fileRead,
        private readonly DocSearchTool $docSearch,
        private readonly MemoryRecallTool $memoryRecall,
        private readonly ?InferenceSpeed $speed = null,
    ) {}

    public function __invoke(
        #[Param('The task, complete on its own: what to find out, and what to report')] string $task,
    ): string {
        $task = trim($task);
        if ($task === '') {
            throw new \RuntimeException('an empty task: say what the sub-agent should find out.');
        }

        // Its own window, sized as the main one: what it reads never reaches
        // the conversation that asked, only what it concludes.
        $budget = new ContextBudget(contextWindow: $this->platform->contextWindow());
        $loop = new AgentLoop(
            $this->platform,
            new Toolbox([$this->listDir, $this->fileFind, $this->grep, $this->fileRead, $this->docSearch, $this->memoryRecall]),
            $this->permissions,
            new SubagentPane(new Terminal(), new MarkdownRenderer()),
            $budget,
            new HistoryCompactor($this->platform, $budget, $this->speed, $this->interrupt),
            $this->interrupt,
            speed: $this->speed,
        );

        $bag = new MessageBag();
        $bag->system(sprintf(self::SYSTEM, $this->paths->root()));
        $bag->user($task);

        $result = $loop->run($bag);

        if ($result->interrupted) {
            return 'The sub-agent was interrupted before it finished.';
        }

        $report = trim($result->content);
        if (mb_strlen($report) > self::MAX_REPORT_CHARS) {
            $report = mb_substr($report, 0, self::MAX_REPORT_CHARS) . "\n… (report cut at " . number_format(self::MAX_REPORT_CHARS) . ' characters)';
        }

        return "Sub-agent report ({$result->iterations} step" . ($result->iterations > 1 ? 's' : '') . "):\n\n" . $report;
    }
}
