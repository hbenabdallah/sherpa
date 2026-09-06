<?php

namespace App\Tool;

use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Project\PathOutsideProjectException;
use App\Project\ProjectPathResolver;

#[AsTool(
    name: 'file_patch',
    description: 'Apply a targeted replacement in a file: replace an exact string with a new string. Safer than full file_write for small edits.',
    permission: Permission::CONFIRM,
)]
class FilePatchTool
{
    public function __construct(
        private readonly ProjectPathResolver $paths = new ProjectPathResolver(),
    ) {}

    public function __invoke(
        #[Param('Path to the file, relative to the project root. Must be inside the project.')] string $path,
        #[Param('Exact string to find in the file (must be unique)')] string $search,
        #[Param('String to replace it with')] string $replace,
    ): string {
        $resolved = $this->paths->resolve($path);

        if (!file_exists($resolved)) {
            return "Error: file not found: {$path}";
        }

        $content = file_get_contents($resolved);
        $count = substr_count($content, $search);

        if ($count === 0) {
            return "Error: search string not found in {$path}";
        }
        if ($count > 1) {
            return "Error: search string appears {$count} times in {$path} — make it more specific to ensure a unique match";
        }

        $newContent = str_replace($search, $replace, $content);
        file_put_contents($resolved, $newContent);

        return "Patched: {$path}";
    }

    public function getDiff(string $path, string $search, string $replace): string
    {
        try {
            $resolved = $this->paths->resolve($path);
        } catch (PathOutsideProjectException $e) {
            return '!! ' . $e->getMessage();
        }

        if (!file_exists($resolved)) {
            return "(file not found: {$path})";
        }

        // Surface up front whether this patch can actually apply, so the user is
        // not asked to approve an edit that __invoke() will refuse anyway.
        $matches = substr_count(file_get_contents($resolved), $search);
        $diff = match (true) {
            $matches === 0 => ['!! search string not found — this patch will fail', ''],
            $matches > 1   => ["!! search string matches {$matches} times — this patch will be refused", ''],
            default        => [],
        };

        $diff[] = "--- a/{$path}";
        $diff[] = "+++ b/{$path}";

        foreach (explode("\n", $search) as $l) {
            $diff[] = "- {$l}";
        }
        foreach (explode("\n", $replace) as $l) {
            $diff[] = "+ {$l}";
        }

        return implode("\n", $diff);
    }
}
