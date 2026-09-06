<?php

namespace App\Tool;

use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Project\PathOutsideProjectException;
use App\Project\PathSuggestions;
use App\Project\ProjectPathResolver;
use App\Tool\Edit\PatchOutcome;
use App\Tool\Edit\SyntaxGuard;
use App\Tool\Edit\TextPatch;

#[AsTool(
    name: 'file_patch',
    description: 'Apply a targeted replacement in a file: replace a string that occurs once with a new string. Safer than full file_write for small edits.',
    permission: Permission::CONFIRM,
)]
class FilePatchTool
{
    public function __construct(
        private readonly ProjectPathResolver $paths = new ProjectPathResolver(),
        private readonly SyntaxGuard $syntax = new SyntaxGuard(),
    ) {}

    /**
     * Failures are thrown, so Toolbox flags them as errors — shown as such, and
     * counted as such — with the same "Error: …" text the model always read.
     */
    public function __invoke(
        #[Param('Path to the file, relative to the project root. Must be inside the project.')] string $path,
        #[Param('The text to replace, copied from the file; it must occur exactly once')] string $search,
        #[Param('The text to put in its place')] string $replace,
    ): string {
        $resolved = $this->paths->resolve($path);

        if (!is_file($resolved)) {
            throw new \RuntimeException("file not found: {$path}" . PathSuggestions::hint($this->paths->root(), $path, directory: false));
        }

        $original = (string) file_get_contents($resolved);
        $outcome = TextPatch::apply($original, $search, $replace);

        if (!$outcome->ok()) {
            throw new \RuntimeException("{$path}: " . $outcome->message());
        }

        // Only a change that breaks a file that parsed is refused: a file
        // already broken may take several patches to repair.
        $problem = $this->syntax->problem($path, (string) $outcome->content);
        if ($problem !== null && $this->syntax->problem($path, $original) === null) {
            throw new \RuntimeException(
                "this change would break {$path}, so it was not applied: {$problem}. "
                . 'The file is unchanged; correct the replacement and try again.',
            );
        }

        file_put_contents($resolved, $outcome->content);

        // Said when it was not the exact text, so the next patch in the
        // session starts from the right habit rather than from the slip.
        return match ($outcome->how) {
            'exact'               => "Patched: {$path}",
            'doubled backslashes' => "Patched: {$path} (the search had its backslashes escaped twice; matched with single ones — write them once)",
            'indentation'         => "Patched: {$path} (matched ignoring indentation at line {$outcome->line}; the file's indentation was kept)",
            default               => "Patched: {$path} (matched after normalising {$outcome->how})",
        };
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

        // Surface up front whether this patch can actually apply — and what it
        // will really change when a fallback placed it — so the user is not
        // asked to approve one edit and shown another.
        $outcome = TextPatch::apply((string) file_get_contents($resolved), $search, $replace);

        if (!$outcome->ok()) {
            $diff = ['!! ' . $outcome->error . ' — this patch will fail', ''];

            foreach (explode("\n", $search) as $l) {
                $diff[] = "- {$l}";
            }

            return implode("\n", $diff);
        }

        $diff = $outcome->how === 'exact' ? [] : ["(placed allowing for: {$outcome->how}, line {$outcome->line})", ''];
        $diff[] = "--- a/{$path}";
        $diff[] = "+++ b/{$path}";

        foreach (explode("\n", $outcome->matched) as $l) {
            $diff[] = "- {$l}";
        }
        foreach (explode("\n", $outcome->replacement) as $l) {
            $diff[] = "+ {$l}";
        }

        return implode("\n", $diff);
    }
}
