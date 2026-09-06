<?php

declare(strict_types=1);

namespace App\Project;

use App\Memory\MemoryStore;
use App\Permission\ProjectPermissions;
use App\Session\SessionStore;
use App\Tool\ShellExecTool;

/**
 * The project this session works in: the directory Sherpa was launched from,
 * either one it knows or a new one held as a draft until the first message.
 * Until save(), memory stays in RAM and nothing is written — quitting before
 * that leaves nothing behind, and the next launch asks again.
 *
 * Everything that follows the project is bound here: where the file tools are
 * confined and which container shell_exec uses, from the start, and once it is
 * real its memory, its grants and its conversations.
 */
class WorkingProject
{
    private ?Project $project = null;
    private bool $saved = false;
    private bool $forgotten = false;

    public function __construct(
        private readonly ProjectStore $store,
        private readonly MemoryStore $memory,
        private readonly ProjectPermissions $permissions,
        private readonly SessionStore $sessions,
        private readonly ProjectPathResolver $paths,
        private readonly ShellExecTool $shell,
    ) {}

    /**
     * Why Sherpa will not treat $directory as a project, or null when it can.
     *
     * A project confines every file tool to its directory. Launched from the
     * home directory, that confinement would cover everything the user owns,
     * and from / everything there is — a project in name only.
     */
    public static function refusal(string $directory, string $home): ?string
    {
        if (!is_dir($directory)) {
            return is_file('/.dockerenv')
                ? "{$directory} is not visible from Sherpa's container: only the projects under "
                    . 'SHERPA_PROJECTS_ROOT are mounted there. Run on the machine (make php), Sherpa opens any directory.'
                : "{$directory} does not exist.";
        }

        $here = ProjectStore::canonical($directory);

        if ($here === '' || $here === '/') {
            return 'Sherpa takes the directory it is launched from as the project, and confines its tools to it: '
                . 'at the root of the disk, that would be the whole system. Move into a project directory.';
        }

        if ($home !== '' && $here === ProjectStore::canonical($home)) {
            return 'Sherpa takes the directory it is launched from as the project, and confines its tools to it: '
                . 'here, that would be your whole home directory. Move into a project directory.';
        }

        return null;
    }

    /** A project Sherpa already knows: bound to its files at once. */
    public function open(Project $project): void
    {
        $this->project = $project;
        $this->saved = true;
        $this->bind($project);
        $this->store->touch($project);
    }

    /**
     * A new directory's project, held until the first message. Memory in RAM,
     * since the prompt reads it and a new project holds nothing anyway; no
     * standing grants, since a grant would be written to projects.yaml.
     */
    public function hold(Project $draft): void
    {
        $this->project = $draft;
        $this->saved = false;
        $this->apply($draft);
        $this->memory->open(':memory:');
        $this->sessions->bind($draft);
    }

    /**
     * The project with new answers (/project edit). A draft stays a draft —
     * editing it is not yet deciding to work here — and takes effect at once
     * either way.
     */
    public function replace(Project $project): void
    {
        $this->project = $project;
        $this->apply($project);

        // The same project under new answers: its memory and its conversation
        // stay as they are — rebinding the sessions would start a new one in
        // the middle of this one.
        if ($this->saved) {
            $this->permissions->setProject($project);
        }
    }

    public function project(): Project
    {
        return $this->project ?? throw new \LogicException('No project is open.');
    }

    public function isSaved(): bool
    {
        return $this->saved;
    }

    /**
     * Make a held project real. Called before the first message, and before
     * anything else that writes about the project.
     *
     * @return bool true when the project was created just now
     */
    public function save(): bool
    {
        // A forgotten project stays forgotten: saving it again now would undo
        // what the user just asked for.
        if ($this->saved || $this->forgotten || $this->project === null) {
            return false;
        }

        // Another session may have saved this directory meanwhile: adopt()
        // then answers with that project, and this session joins it.
        $project = $this->store->adopt($this->project);
        $this->project = $project;
        $this->saved = true;
        $this->bind($project);
        $this->store->touch($project);

        return true;
    }

    /**
     * Forget this project: its entry, its memory, its conversations. The
     * directory is not Sherpa's and is never touched, and nothing is written
     * afterwards — not the conversation on the way out, not the facts from it —
     * which would bring back one file at a time what was just deleted.
     *
     * @return bool false for a project that was never saved: nothing to forget
     */
    public function forget(): bool
    {
        if (!$this->saved || $this->project === null) {
            return false;
        }

        $this->store->delete($this->project->slug);
        $this->memory->open(':memory:');
        $this->sessions->unbind();
        $this->saved = false;
        $this->forgotten = true;

        return true;
    }

    public function isForgotten(): bool
    {
        return $this->forgotten;
    }

    private function bind(Project $project): void
    {
        $this->apply($project);
        $this->memory->open($project->memoryDb);
        $this->permissions->setProject($project);
        $this->sessions->bind($project);
    }

    /** What a draft needs as much as a saved project: where the tools may act. */
    private function apply(Project $project): void
    {
        // One shared resolver confines every filesystem tool to this project.
        $this->paths->setRoot($project->path);
        $this->shell->setDockerContainer(
            $project->docker->enabled && $project->docker->container !== '' ? $project->docker->container : null,
        );
    }
}
