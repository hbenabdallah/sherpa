<?php

namespace App\Tool;

use App\Agent\TodoList;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;

#[AsTool(
    name: 'todo_write',
    description: 'Write the plan for a task of several steps, and keep it up to date: send the WHOLE list every time, one task per line — "[ ] to do", "[>] in progress", "[x] done". Mark a task in progress before starting it and done as soon as it is. Not for a one-step request.',
    permission: Permission::AUTO,
)]
class TodoWriteTool
{
    public function __construct(private readonly TodoList $todos) {}

    public function __invoke(
        #[Param('The whole list, one task per line, each starting with [ ], [>] or [x]')] string $tasks,
    ): string {
        $count = $this->todos->replace($tasks);

        if ($count === 0) {
            return 'Plan cleared: no task line was found. Write each task as "[ ] task", "[>] task" or "[x] task", one per line.';
        }

        return 'Plan (' . $this->todos->progress() . "):\n" . $this->todos->asText();
    }
}
