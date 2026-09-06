<?php

namespace App\Tool;

use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;

#[AsTool(
    name: 'job_output',
    description: 'Read what a background job (shell_exec with background=true) printed since it was last read, and whether it is still running.',
    // It reads the output of a command the user already allowed.
    permission: Permission::AUTO,
)]
class JobOutputTool
{
    public function __construct(private readonly ShellExecTool $shell) {}

    public function __invoke(#[Param('The job id shell_exec gave')] int $id): string
    {
        return $this->shell->jobOutput($id);
    }
}
