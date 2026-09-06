<?php

namespace App\Tool;

use App\Agent\ContextBudget;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Project\PathSuggestions;
use App\Project\ProjectPathResolver;

#[AsTool(
    name: 'file_read',
    description: 'Read the contents of a file in the project. Returns file content with line numbers.',
    permission: Permission::AUTO,
)]
class FileReadTool
{
    /**
     * Share of the prompt window one read may fill, with the floor a 32k
     * window gets. Unbounded, one lock file or generated fixture filled the
     * window and the next request was refused: past the cap the model is told
     * where the read stopped, and reads on with an offset if it needs to.
     */
    private const READ_SHARE = 0.35;
    private const MIN_CHARS = 24000;
    private const MAX_CHARS = 400000;

    /** Longest line shown whole: minified code is one line of a megabyte. */
    private const MAX_LINE_CHARS = 2000;

    public function __construct(
        private readonly ProjectPathResolver $paths = new ProjectPathResolver(),
        private readonly ?ContextBudget $budget = null,
    ) {}

    public function __invoke(
        #[Param('Path to the file, relative to the project root. Must be inside the project.')] string $path,
        #[Param('Line to start reading from (1-based, optional)')] ?int $offset = null,
        #[Param('Number of lines to read (optional)')] ?int $limit = null,
    ): string {
        $resolved = $this->paths->resolve($path);

        // Thrown rather than returned: Toolbox turns them into the same
        // "Error: …" text, but flagged as a failure — which the pane shows as
        // one, and which SearchTrace needs, since a read that found nothing is
        // one more guess, not the end of a search.
        if (!file_exists($resolved)) {
            throw new \RuntimeException("file not found: {$path}" . PathSuggestions::hint($this->paths->root(), $path, directory: false));
        }
        // Models do ask for a directory — the project root, typically. file()
        // on one prints a PHP notice into the terminal and returns nothing,
        // which read as a successful read of an empty file.
        if (is_dir($resolved)) {
            throw new \RuntimeException("{$path} is a directory, not a file: use project_grep to find the file you want");
        }
        if (!is_readable($resolved)) {
            throw new \RuntimeException("file is not readable: {$path}");
        }

        $lines = file($resolved, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException("could not read file: {$path}");
        }

        $total = count($lines);
        if ($offset !== null || $limit !== null) {
            $start = max(0, ($offset ?? 1) - 1);
            $lines = array_slice($lines, $start, $limit);
            $startLine = $start + 1;
        } else {
            $startLine = 1;
        }

        $cap = $this->budget?->shareInChars(self::READ_SHARE, self::MIN_CHARS, self::MAX_CHARS) ?? self::MIN_CHARS;
        $numbered = [];
        $chars = 0;
        foreach ($lines as $i => $line) {
            $row = sprintf('%4d | %s', $startLine + $i, LongLine::clip($line, self::MAX_LINE_CHARS));
            if ($numbered !== [] && $chars + strlen($row) > $cap) {
                $last = $startLine + $i - 1;
                $numbered[] = sprintf('... (truncated to fit the context: lines %d-%d of %d shown; read on with offset=%d)',
                    $startLine, $last, $total, $last + 1);
                break;
            }
            $numbered[] = $row;
            $chars += strlen($row) + 1;
        }

        return implode("\n", $numbered);
    }
}
