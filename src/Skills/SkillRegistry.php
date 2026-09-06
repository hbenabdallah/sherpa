<?php

namespace App\Skills;

class SkillRegistry
{
    /** @var array<string, Skill> */
    private array $skills = [];

    private string $globalDir;
    private ?string $projectDir = null;

    public function __construct()
    {
        $this->globalDir = ($_SERVER['HOME'] ?? getenv('HOME')) . '/.config/sherpa/skills';
    }

    public function setProjectPath(string $projectPath): void
    {
        $this->projectDir = $projectPath . '/.sherpa/skills';
    }

    public function load(): void
    {
        $this->skills = [];
        $this->loadDir($this->globalDir);
        if ($this->projectDir !== null) {
            $this->loadDir($this->projectDir);
        }
    }

    private function loadDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*.md') as $file) {
            $content = file_get_contents($file);
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
        if (empty($this->skills)) {
            return '(aucun skill chargé)';
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

    public function create(string $name, string $content): Skill
    {
        if (!is_dir($this->globalDir)) {
            mkdir($this->globalDir, 0755, true);
        }

        $path = $this->globalDir . '/' . $name . '.md';
        file_put_contents($path, $content);

        $skill = new Skill(
            name: $name,
            description: $this->extractFirstLine($content),
            content: $content,
            path: $path,
        );
        $this->skills[$name] = $skill;
        return $skill;
    }
}
