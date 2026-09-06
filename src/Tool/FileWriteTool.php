<?php

namespace App\Tool;

use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Project\PathOutsideProjectException;
use App\Project\ProjectPathResolver;

#[AsTool(
    name: 'file_write',
    description: 'Create or overwrite a file with the given content.',
    permission: Permission::CONFIRM,
)]
class FileWriteTool
{
    public function __construct(
        private readonly ProjectPathResolver $paths = new ProjectPathResolver(),
    ) {}

    public function __invoke(
        #[Param('Path to the file, relative to the project root. Must be inside the project.')] string $path,
        #[Param('Full content to write to the file')] string $content,
    ): string {
        $resolved = $this->paths->resolve($path);

        $dir = dirname($resolved);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return "Error: could not create directory: {$dir}";
        }

        $exists = file_exists($resolved);
        if (file_put_contents($resolved, $content) === false) {
            return "Error: could not write to file: {$path}";
        }

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
            $added = array_map(fn($l) => "+ {$l}", $lines);
            return "(new file)\n" . implode("\n", $added);
        }

        $oldContent = file_get_contents($resolved);
        return $this->unifiedDiff($oldContent, $newContent, $path);
    }

    private function unifiedDiff(string $old, string $new, string $label): string
    {
        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);

        $diff = ["--- a/{$label}", "+++ b/{$label}"];
        $maxLen = max(count($oldLines), count($newLines));

        $i = 0;
        $j = 0;
        while ($i < count($oldLines) || $j < count($newLines)) {
            $oldLine = $oldLines[$i] ?? null;
            $newLine = $newLines[$j] ?? null;

            if ($oldLine === $newLine) {
                $diff[] = "  {$oldLine}";
                $i++;
                $j++;
            } elseif ($oldLine !== null) {
                $diff[] = "- {$oldLine}";
                $i++;
            } else {
                $diff[] = "+ {$newLine}";
                $j++;
            }

            if (count($diff) > 80) {
                $diff[] = '... (diff truncated)';
                break;
            }
        }

        return implode("\n", $diff);
    }
}
