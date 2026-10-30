<?php

declare(strict_types=1);

namespace App\Tool;

use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Project\PathOutsideProjectException;
use App\Project\ProjectPathResolver;
use App\Tool\Edit\Files;
use App\Tool\Edit\LineDiff;
use App\Tool\Edit\ProposedEdit;
use App\Tool\Edit\SyntaxGuard;
use RuntimeException;

#[AsTool(
    name: 'file_write',
    description: 'Create or overwrite a file with the given content.',
    permission: Permission::CONFIRM,
)]
final readonly class FileWriteTool
{
    public function __construct(
        private ProjectPathResolver $paths = new ProjectPathResolver(),
        private SyntaxGuard $syntax = new SyntaxGuard(),
        // The disk, or the editor's buffers under `sherpa acp`.
        private Files $files = new Files(),
    ) {}

    public function __invoke(
        #[Param('Path to the file, relative to the project root. Must be inside the project.')]
        string $path,
        #[Param('Full content to write to the file')]
        string $content,
    ): string {
        $resolved = $this->paths->resolve($path);

        $exists = file_exists($resolved);

        // Same rule as file_patch: never turn a file that parses into one that
        // does not. A new file has nothing to protect but still has to parse.
        $problem = $this->syntax->problem($path, $content);
        if ($problem !== null && (!$exists || $this->syntax->problem($path, (string) $this->files->read($resolved)) === null)) {
            throw new RuntimeException(
                "this content would not parse, so {$path} was not written: {$problem}. Correct it and write again.",
            );
        }

        // Thrown, like every other failure here, so it is shown and counted
        // as one: returned as text, it came back marked as a success.
        $this->files->write($resolved, $path, $content);

        $lines = substr_count($content, "\n") + 1;
        $action = $exists ? 'Updated' : 'Created';

        return "{$action}: {$path} ({$lines} lines)";
    }

    /**
     * Preview for the confirmation overlay. A refusal is rendered rather than
     * thrown, so the user sees why the call will be rejected instead of the TUI
     * dying mid-prompt.
     */
    public function getDiff(string $path, string $newContent): string
    {
        try {
            $resolved = $this->paths->resolve($path);
        } catch (PathOutsideProjectException $e) {
            return '!! ' . $e->getMessage();
        }

        if (!file_exists($resolved)) {
            $lines = explode("\n", $newContent);
            $added = array_map(fn($l): string => "+ {$l}", $lines);

            return "(new file)\n" . implode("\n", $added);
        }

        return LineDiff::unified((string) $this->files->read($resolved), $newContent, $this->paths->relative($resolved));
    }

    /** The file before and after, or null for a path the tool will refuse. */
    public function proposal(string $path, string $newContent): ?ProposedEdit
    {
        try {
            $resolved = $this->paths->resolve($path);
        } catch (PathOutsideProjectException) {
            return null;
        }

        return new ProposedEdit($resolved, is_file($resolved) ? (string) $this->files->read($resolved) : null, $newContent);
    }
}
