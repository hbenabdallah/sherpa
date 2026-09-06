<?php

namespace App\Tool;

use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;

#[AsTool(
    name: 'job_stop',
    description: 'Stop a background job and everything it started. Stop the jobs you started once they are no longer needed.',
    // It stops only what Sherpa itself started.
    permission: Permission::AUTO,
)]
class JobStopTool
{
    public function __construct(private readonly BackgroundJobs $jobs) {}

    public function __invoke(#[Param('The job id shell_exec gave')] int $id): string
    {
        return $this->jobs->stop($id);
    }
}
