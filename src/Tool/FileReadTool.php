<?php

namespace App\Tool;

use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Project\ProjectPathResolver;

#[AsTool(
    name: 'file_read',
    description: 'Read the contents of a file in the project. Returns file content with line numbers.',
    permission: Permission::AUTO,
)]
class FileReadTool
{
    public function __construct(
        private readonly ProjectPathResolver $paths = new ProjectPathResolver(),
    ) {}

    public function __invoke(
        #[Param('Path to the file, relative to the project root. Must be inside the project.')] string $path,
        #[Param('Line to start reading from (1-based, optional)')] ?int $offset = null,
        #[Param('Number of lines to read (optional)')] ?int $limit = null,
    ): string {
        $resolved = $this->paths->resolve($path);

        if (!file_exists($resolved)) {
            return "Error: file not found: {$path}";
        }
        if (!is_readable($resolved)) {
            return "Error: file is not readable: {$path}";
        }

        $lines = file($resolved, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return "Error: could not read file: {$path}";
        }

        if ($offset !== null || $limit !== null) {
            $start = max(0, ($offset ?? 1) - 1);
            $lines = array_slice($lines, $start, $limit);
            $startLine = $start + 1;
        } else {
            $startLine = 1;
        }

        $numbered = [];
        foreach ($lines as $i => $line) {
            $numbered[] = sprintf('%4d | %s', $startLine + $i, $line);
        }

        return implode("\n", $numbered);
    }
}
