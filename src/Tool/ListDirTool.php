<?php

namespace App\Tool;

use App\Agent\ContextBudget;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Project\PathSuggestions;
use App\Project\ProjectPathResolver;

#[AsTool(
    name: 'list_dir',
    description: 'List a project directory as a tree: subdirectories first, then files. Use it when you do not know where something lives, instead of guessing file names.',
    permission: Permission::AUTO,
)]
class ListDirTool
{
    /**
     * Directories named but never opened. What is in them is somebody else's
     * code or generated output, and listing it would drown the project in it —
     * but saying they exist is still information: a vendor/ means Composer.
     */
    private const NOT_EXPANDED = ['vendor', 'node_modules', '.git', 'var', '.idea', '.vscode', '__pycache__', '.venv', 'target'];

    private const DEFAULT_DEPTH = 2;
    private const MAX_DEPTH = 4;

    /** Entries shown per directory before the rest are counted rather than named. */
    private const PER_DIRECTORY = 40;

    /** Floors and ceiling on lines, scaled with the window like project_grep's. */
    private const MIN_LINES = 150;
    private const MAX_LINES = 1200;
    private const LINE_SHARE = 0.04;
    private const CHARS_PER_LINE = 40;

    private int $lines = 0;
    private int $cap = self::MIN_LINES;
    private bool $truncated = false;

    public function __construct(
        private readonly ProjectPathResolver $paths = new ProjectPathResolver(),
        // Optional so the tool stays constructible on its own; absent, the
        // floor applies.
        private readonly ?ContextBudget $budget = null,
    ) {}

    public function __invoke(
        #[Param('Directory to list, relative to the project root (optional, defaults to the root)')] ?string $path = null,
        #[Param('How many levels to open, 1 to 4 (optional, defaults to 2)')] ?int $depth = null,
    ): string {
        $root = $this->paths->root();
        $target = $path === null || trim($path) === '' || trim($path) === '.'
            ? $root
            : $this->paths->resolve($path);

        if (!file_exists($target)) {
            throw new \RuntimeException("directory not found: {$path}" . PathSuggestions::hint($root, (string) $path, directory: true));
        }
        if (!is_dir($target)) {
            throw new \RuntimeException("{$path} is a file, not a directory: use file_read to read it");
        }

        $depth = max(1, min(self::MAX_DEPTH, $depth ?? self::DEFAULT_DEPTH));
        $this->lines = 0;
        $this->truncated = false;
        $this->cap = $this->lineCap();

        $out = [];
        $this->walk($target, $depth, '', $out);

        if ($out === []) {
            return '(empty directory)';
        }

        if ($this->truncated) {
            $out[] = "… (truncated at {$this->cap} lines: list a subdirectory to see more)";
        }

        return implode("\n", $out);
    }

    /** @param string[] $out */
    private function walk(string $dir, int $depth, string $indent, array &$out): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            $out[] = $indent . '(unreadable)';

            return;
        }

        $dirs = [];
        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            // A symlinked directory is named, not followed: it may lead out of
            // the project, and the resolver is what decides what is inside.
            is_dir("{$dir}/{$entry}") && !is_link("{$dir}/{$entry}") ? $dirs[] = $entry : $files[] = $entry;
        }

        natcasesort($dirs);
        natcasesort($files);
        $shown = 0;
        $hidden = count($dirs) + count($files) - self::PER_DIRECTORY;

        foreach ($dirs as $name) {
            if ($shown++ >= self::PER_DIRECTORY || !$this->room()) {
                break;
            }

            $full = "{$dir}/{$name}";

            if (in_array($name, self::NOT_EXPANDED, true)) {
                $out[] = "{$indent}{$name}/ (not expanded)";
                continue;
            }

            if ($depth <= 1) {
                $count = count(array_diff(@scandir($full) ?: [], ['.', '..']));
                $out[] = "{$indent}{$name}/ ({$count} " . ($count === 1 ? 'entry' : 'entries') . ')';
                continue;
            }

            $out[] = "{$indent}{$name}/";
            $this->walk($full, $depth - 1, $indent . '  ', $out);
        }

        foreach ($files as $name) {
            if ($shown++ >= self::PER_DIRECTORY || !$this->room()) {
                break;
            }

            $out[] = $indent . $name;
        }

        if ($hidden > 0 && !$this->truncated) {
            $out[] = "{$indent}… and {$hidden} more";
        }
    }

    /** Counts a line, and says whether there was room for it. */
    private function room(): bool
    {
        if ($this->lines >= $this->cap) {
            $this->truncated = true;

            return false;
        }

        $this->lines++;

        return true;
    }

    private function lineCap(): int
    {
        if ($this->budget === null) {
            return self::MIN_LINES;
        }

        $chars = $this->budget->shareInChars(
            self::LINE_SHARE,
            self::MIN_LINES * self::CHARS_PER_LINE,
            self::MAX_LINES * self::CHARS_PER_LINE,
        );

        return max(self::MIN_LINES, intdiv($chars, self::CHARS_PER_LINE));
    }
}
