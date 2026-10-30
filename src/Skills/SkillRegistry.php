<?php

declare(strict_types=1);

namespace App\Skills;

class SkillRegistry
{
    /** @var array<string, Skill> */
    private array $skills = [];

    private readonly string $globalDir;

    private ?string $projectDir = null;

    private ?string $projectRoot = null;

    public function __construct()
    {
        $this->globalDir = ($_SERVER['HOME'] ?? getenv('HOME')) . '/.config/sherpa/skills';
    }

    public function setProjectPath(string $projectPath): void
    {
        $this->projectDir = $projectPath . '/.sherpa/skills';
        $this->projectRoot = $projectPath;
    }

    public function load(): void
    {
        $this->skills = [];
        $this->loadDir($this->globalDir);
        if ($this->projectDir !== null) {
            $this->loadDir($this->projectDir, $this->projectRoot);
        }
    }

    /**
     * @param string|null $within the project, for its skills: skill_load hands
     *                            a skill's text to the model without asking,
     *                            and a symlink in .sherpa/skills/ pointing at
     *                            ~/.ssh/id_rsa was a skill like any other
     */
    private function loadDir(string $dir, ?string $within = null): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $root = $within === null ? null : realpath($within);

        foreach (glob($dir . '/*.md') ?: [] as $file) {
            $real = realpath($file);
            if ($within !== null && ($real === false || $root === false || !str_starts_with($real, $root . '/'))) {
                continue;
            }

            $content = (string) file_get_contents($file);
            $name = basename($file, '.md');
            $description = $this->extractFirstLine($content);

            $this->skills[$name] = new Skill(
                name: $name,
                description: $description,
                content: $content,
                path: $file,
            );
        }
    }

    private function extractFirstLine(string $content): string
    {
        // Skip H1 title line, return first paragraph or subtitle
        $lines = explode("\n", trim($content));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            return $line;
        }

        return '';
    }

    public function getIndex(): string
    {
        if ($this->skills === []) {
            return '(no skill loaded)';
        }

        $lines = [];
        foreach ($this->skills as $name => $skill) {
            $lines[] = "- {$name} : {$skill->description}";
        }

        return implode("\n", $lines);
    }

    public function get(string $name): ?Skill
    {
        return $this->skills[$name] ?? null;
    }

    /** @return Skill[] */
    public function all(): array
    {
        return array_values($this->skills);
    }
}
