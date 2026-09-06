<?php

namespace App\Permission;

use App\Project\Project;
use App\Project\ProjectStore;

/**
 * Standing permissions, scoped to one project and persisted in projects.yaml.
 *
 * The distinction from SessionPermissions is the whole point: a session grant
 * dies with the process, this one does not. Choosing "[p] Projet" for shell_exec
 * means every future session runs commands in that project unprompted, so the
 * grants have to be listable and revocable — see /permissions.
 */
class ProjectPermissions
{
    private ?Project $project = null;

    public function __construct(private readonly ProjectStore $store) {}

    public function setProject(Project $project): void
    {
        $this->project = $project;
    }

    public function isAllowed(string $toolName): bool
    {
        return $this->project !== null
            && in_array($toolName, $this->project->allowedTools, true);
    }

    public function allow(string $toolName): void
    {
        // No project bound means no file to write to. Silently keeping the grant
        // in memory would be the old bug in a new place: the user is told the
        // permission is permanent, so it either persists or it is not granted.
        if ($this->project === null) {
            return;
        }

        $this->store->allowTool($this->project, $toolName);
    }

    public function revoke(string $toolName): bool
    {
        return $this->project !== null
            && $this->store->revokeTool($this->project, $toolName);
    }

    public function revokeAll(): int
    {
        return $this->project === null ? 0 : $this->store->revokeAllTools($this->project);
    }

    /** @return array<int, string> */
    public function all(): array
    {
        return $this->project?->allowedTools ?? [];
    }
}
