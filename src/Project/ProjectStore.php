<?php

namespace App\Project;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\UnicodeString;
use Symfony\Component\Yaml\Yaml;

class ProjectStore
{
    private string $configFile;
    /** @var array<string, Project> */
    private array $projects = [];

    public function __construct(private readonly StackDetector $detector)
    {
        $this->configFile = Project::configDir() . '/projects.yaml';
        $this->load();
    }

    /**
     * Slugs this session created or changed, and ones it deleted.
     *
     * save() writes the file back from what is on disk plus these, rather than
     * from the whole in-memory map. Two Sherpa sessions in two terminals is the
     * normal way to use a per-project agent, and each used to overwrite the
     * other: a permission granted in one silently deleted a project created in
     * the other. Hand-editing the file while a session ran was reverted the
     * same way — and hand-editing is currently the only way to change a
     * project.
     *
     * @var array<string, true>
     */
    private array $touched = [];

    /** @var array<string, true> */
    private array $removed = [];

    /** What the file looked like when we last read or wrote it. */
    private ?string $fingerprint = null;

    private function load(): void
    {
        $this->projects = $this->read();
    }

    /** @return array<string, Project> */
    private function read(): array
    {
        $this->fingerprint = $this->fingerprintOfFile();

        if (!file_exists($this->configFile)) {
            return [];
        }

        $data = Yaml::parseFile($this->configFile);
        $projects = [];

        foreach ($data['projects'] ?? [] as $slug => $projectData) {
            $projects[$slug] = Project::fromArray((string) $slug, $projectData);
        }

        // sort by last_used_at desc
        uasort($projects, fn(Project $a, Project $b) => $b->lastUsedAt <=> $a->lastUsedAt);

        return $projects;
    }

    private function save(): void
    {
        $dir = dirname($this->configFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Re-read first: whatever another session wrote since we loaded is the
        // base, and only the projects this session actually changed are applied
        // on top of it.
        $merged = $this->read();

        foreach (array_keys($this->touched) as $slug) {
            if (isset($this->projects[$slug])) {
                $merged[$slug] = $this->projects[$slug];
            }
        }

        foreach (array_keys($this->removed) as $slug) {
            unset($merged[$slug]);
        }

        $data = ['projects' => []];
        foreach ($merged as $slug => $project) {
            $data['projects'][$slug] = $project->toArray();
        }

        file_put_contents($this->configFile, Yaml::dump($data, 4, 2));

        $this->projects = $merged;
        $this->touched = [];
        $this->removed = [];
        $this->fingerprint = $this->fingerprintOfFile();
    }

    /**
     * Whether someone else has written the file since we last touched it.
     *
     * The session read the project's path, its container and its stack at
     * startup and has been running on them ever since, so a change out there is
     * something the user has to be told about — the running session cannot
     * adopt it.
     */
    public function changedOnDisk(): bool
    {
        return $this->fingerprint !== $this->fingerprintOfFile();
    }

    private function fingerprintOfFile(): ?string
    {
        if (!is_file($this->configFile)) {
            return null;
        }

        clearstatcache(true, $this->configFile);

        return @md5_file($this->configFile) ?: null;
    }

    /** @return Project[] */
    public function all(): array
    {
        return array_values($this->projects);
    }

    public function get(string $slug): ?Project
    {
        return $this->projects[$slug] ?? null;
    }

    /**
     * The project a directory belongs to, if any.
     *
     * Typing `sherpa` inside a project and then picking that same project off a
     * menu is a step nobody needs. The deepest match wins, so a project nested
     * inside another opens as itself rather than as its parent.
     */
    public function forDirectory(string $directory): ?Project
    {
        $directory = rtrim($directory, '/');
        if ($directory === '') {
            return null;
        }

        $best = null;
        $depth = -1;

        foreach ($this->projects as $project) {
            $root = rtrim($project->path, '/');

            if ($root === '' || ($directory !== $root && !str_starts_with($directory, $root . '/'))) {
                continue;
            }

            $length = strlen($root);
            if ($length > $depth) {
                $best = $project;
                $depth = $length;
            }
        }

        return $best;
    }

    public function create(string $name, string $path, DockerConfig $docker): Project
    {
        $slug = $this->slugify($name);
        $stack = $this->detector->detect($path)->summary();
        $memoryDb = Project::defaultMemoryDb($slug);

        // Ensure memory directory exists
        $memoryDir = dirname($memoryDb);
        if (!is_dir($memoryDir)) {
            mkdir($memoryDir, 0755, true);
        }

        $project = new Project(
            slug: $slug,
            name: $name,
            path: $path,
            memoryDb: $memoryDb,
            docker: $docker,
            stack: $stack,
            createdAt: new \DateTimeImmutable(),
            lastUsedAt: new \DateTimeImmutable(),
        );

        $this->projects[$slug] = $project;
        $this->touched[$slug] = true;
        $this->save();

        return $project;
    }

    /**
     * Change a project's name, location or container.
     *
     * The slug does not move with the name. It names the memory directory on
     * disk and it is what `sherpa -p` takes, so re-slugging a rename would
     * strand everything Sherpa has learned about the project and break every
     * shell alias pointing at it. The name is a label; the slug is the
     * identity, and identities do not get renamed.
     */
    public function update(Project $project, string $name, string $path, DockerConfig $docker): Project
    {
        $updated = new Project(
            slug: $project->slug,
            name: $name,
            path: $path,
            memoryDb: $project->memoryDb,
            docker: $docker,
            stack: $this->detector->detect($path)->summary(),
            createdAt: $project->createdAt,
            lastUsedAt: $project->lastUsedAt,
            allowedTools: $project->allowedTools,
        );

        $this->projects[$project->slug] = $updated;
        $this->touched[$project->slug] = true;
        $this->save();

        return $updated;
    }

    /**
     * Forget a project, and its memory with it.
     *
     * Leaving the database behind is worse than removing it: slugs are derived
     * from the name, so re-adding a project called the same thing lands on the
     * same slug and silently inherits everything Sherpa believed about the one
     * that was deleted.
     */
    public function delete(string $slug): void
    {
        $project = $this->projects[$slug] ?? null;
        unset($this->projects[$slug]);
        $this->removed[$slug] = true;
        $this->save();

        if ($project === null) {
            return;
        }

        $memoryDir = dirname($project->memoryDb);

        // Only a directory this class named itself, and only if it is where
        // memory databases live — never a path that came out of the YAML file
        // pointing somewhere else.
        if ($memoryDir === dirname(Project::defaultMemoryDb($slug))) {
            try {
                (new Filesystem())->remove($memoryDir);
            } catch (\Throwable) {
            }
        }
    }

    public function touch(Project $project): void
    {
        $project->lastUsedAt = new \DateTimeImmutable();

        // The stack was detected once, when the project was added, and never
        // looked at again — so adding a framework left Sherpa describing the
        // project as it was the day it was created. Opening it is the moment
        // to look again.
        $project->stack = $this->detector->detect($project->path)->summary();
        $this->projects[$project->slug] = $project;
        $this->touched[$project->slug] = true;
        $this->save();
    }

    public function isEmpty(): bool
    {
        return empty($this->projects);
    }

    /**
     * Record a standing permission for this project. Written through to disk
     * immediately: the grant only means anything if it outlives the session
     * that made it, and a crash before the next save would silently drop it.
     */
    public function allowTool(Project $project, string $toolName): void
    {
        if (in_array($toolName, $project->allowedTools, true)) {
            return;
        }

        $project->allowedTools[] = $toolName;
        $this->projects[$project->slug] = $project;
        $this->touched[$project->slug] = true;
        $this->save();
    }

    public function revokeTool(Project $project, string $toolName): bool
    {
        $remaining = array_values(array_diff($project->allowedTools, [$toolName]));
        if (count($remaining) === count($project->allowedTools)) {
            return false;
        }

        $project->allowedTools = $remaining;
        $this->projects[$project->slug] = $project;
        $this->touched[$project->slug] = true;
        $this->save();

        return true;
    }

    public function revokeAllTools(Project $project): int
    {
        $count = count($project->allowedTools);
        if ($count === 0) {
            return 0;
        }

        $project->allowedTools = [];
        $this->projects[$project->slug] = $project;
        $this->touched[$project->slug] = true;
        $this->save();

        return $count;
    }

    /**
     * The slug is not cosmetic: it names the memory directory and it is what
     * `sherpa -p` takes on the command line.
     *
     * Lowercasing and stripping everything outside [a-z0-9] dropped accented
     * letters outright, so "Éditeur" became "diteur" — in a tool whose entire
     * interface is in French. Transliterate first.
     */
    private function slugify(string $name): string
    {
        $slug = (new UnicodeString($name))->ascii()->lower()->toString();
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        // A name with no ASCII spelling at all leaves nothing behind, and an
        // empty slug is a blank key in projects.yaml and a directory called
        // "projects//memory.db". Derive one from the name so that retyping the
        // same name reaches the same project rather than a new one each time.
        if ($slug === '') {
            $slug = 'projet-' . substr(md5($name), 0, 6);
        }

        // ensure uniqueness
        $base = $slug;
        $i = 2;
        while (isset($this->projects[$slug])) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
