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
     * Slugs this session created, changed or deleted: save() writes the file
     * back from what is on disk plus these. Two terminals is the normal way to
     * use a per-project agent, and writing the whole map back meant each
     * session silently undid the other — hand edits included.
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
     * Whether someone else wrote the file since we last touched it. The session
     * has been running on what it read at startup and cannot adopt a change, so
     * the user has to be told.
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
     * The project a directory belongs to. The deepest match wins, so a project
     * nested in another opens as itself; paths are compared once resolved, or a
     * checkout reached through a symlink becomes a second project.
     */
    public function forDirectory(string $directory): ?Project
    {
        $directory = self::canonical($directory);
        if ($directory === '') {
            return null;
        }

        $best = null;
        $depth = -1;

        foreach ($this->projects as $project) {
            $root = self::canonical($project->path);

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

    /** A directory as the filesystem names it: symlinks resolved, no trailing slash. */
    public static function canonical(string $directory): string
    {
        $resolved = realpath($directory);

        return rtrim($resolved !== false ? $resolved : $directory, '/');
    }

    /**
     * A project for $path, not yet written anywhere: opening Sherpa somewhere
     * is not deciding to work there. It exists only in this session until the
     * first message (see adopt()), and quitting before that leaves nothing.
     */
    public function draft(string $path, string $name, DockerConfig $docker): Project
    {
        $slug = $this->slugify($name);

        return new Project(
            slug: $slug,
            name: $name,
            path: self::canonical($path),
            memoryDb: Project::defaultMemoryDb($slug),
            docker: $docker,
            stack: $this->detector->detect($path)->summary(),
            createdAt: new \DateTimeImmutable(),
            lastUsedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Write a draft down; the project exists from here on. One per directory:
     * if another terminal saved this one meanwhile, that project is the one,
     * rather than a twin under a new slug with half the history each.
     */
    public function adopt(Project $draft): Project
    {
        // What another session wrote since this one loaded.
        $this->projects = $this->read() + $this->projects;

        foreach ($this->projects as $project) {
            if (self::canonical($project->path) === self::canonical($draft->path)) {
                return $project;
            }
        }

        // The slug was free when drafted; it may not be any more.
        $project = isset($this->projects[$draft->slug])
            ? $this->draft($draft->path, $draft->name, $draft->docker)
            : $draft;

        $memoryDir = dirname($project->memoryDb);
        if (!is_dir($memoryDir)) {
            mkdir($memoryDir, 0755, true);
        }

        $this->projects[$project->slug] = $project;
        $this->touched[$project->slug] = true;
        $this->save();

        return $project;
    }

    public function create(string $name, string $path, DockerConfig $docker): Project
    {
        return $this->adopt($this->draft($path, $name, $docker));
    }

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
     * Forget a project, and its memory with it: slugs come from the name, so a
     * project re-added under the same name would land on the same slug and
     * inherit everything Sherpa believed about the deleted one.
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

        // The directory this class names after the slug — memory by default,
        // saved conversations always — and nothing else: a memory database the
        // YAML file points somewhere else is not Sherpa's to remove, and is left
        // where it is. Only the directory it would have lived in goes.
        $own = dirname(Project::defaultMemoryDb($slug));

        try {
            (new Filesystem())->remove($own);
        } catch (\Throwable) {
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
     * The slug names the memory directory and the conversations beside it, so
     * accented letters are transliterated rather than stripped: "Éditeur" must
     * not become "diteur".
     *
     * Cyrillic and Greek are spelled out here because, without intl, Symfony
     * falls back on the C library and the libraries disagree: glibc (Docker)
     * turns "Проект" into "proekt", musl (the static PHP on the host) into
     * "??????" and then a hash. A table does not depend on which libc is
     * underneath. Anything else still falls back to the hash, everywhere.
     */
    private const TRANSLITERATION = [
        // Russian, Ukrainian, Belarusian
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'є' => 'ye', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'і' => 'i', 'ї' => 'yi', 'й' => 'y', 'к' => 'k',
        'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ў' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        // Greek, accented vowels included
        'α' => 'a', 'ά' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'έ' => 'e', 'ζ' => 'z',
        'η' => 'i', 'ή' => 'i', 'θ' => 'th', 'ι' => 'i', 'ί' => 'i', 'ϊ' => 'i', 'ΐ' => 'i', 'κ' => 'k',
        'λ' => 'l', 'μ' => 'm', 'ν' => 'n', 'ξ' => 'x', 'ο' => 'o', 'ό' => 'o', 'π' => 'p', 'ρ' => 'r',
        'σ' => 's', 'ς' => 's', 'τ' => 't', 'υ' => 'y', 'ύ' => 'y', 'ϋ' => 'y', 'ΰ' => 'y', 'φ' => 'f',
        'χ' => 'ch', 'ψ' => 'ps', 'ω' => 'o', 'ώ' => 'o',
    ];

    private function slugify(string $name): string
    {
        $spelled = strtr(mb_strtolower($name), self::TRANSLITERATION);
        $slug = (new UnicodeString($spelled))->ascii()->lower()->toString();
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        // A name with no ASCII spelling at all leaves nothing behind, and an
        // empty slug is a blank key in projects.yaml and a directory called
        // "projects//memory.db". Derive one from the name so that retyping the
        // same name reaches the same project rather than a new one each time.
        if ($slug === '') {
            $slug = 'project-' . substr(md5($name), 0, 6);
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
