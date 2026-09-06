<?php

namespace App\Tool;

use App\Agent\ContextBudget;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Project\ProjectPathResolver;
use Symfony\Component\Process\Process;

#[AsTool(
    name: 'project_grep',
    description: 'Search for a pattern in project files using grep. Returns matching lines with filenames and line numbers.',
    permission: Permission::AUTO,
)]
class ProjectGrepTool
{
    /**
     * Floors, chosen against a 32k window and kept as the small-window
     * behaviour. Above that they scale: a search truncated at fifty lines sends
     * the model back for a narrower one, and each of those round trips costs a
     * whole turn to recover matches that would have fitted.
     */
    private const MIN_RESULTS = 50;
    private const MAX_RESULTS = 600;

    /** Matches reported per file before grep moves on. */
    private const MIN_PER_FILE = 5;
    private const MAX_PER_FILE = 15;

    /** Share of the prompt window one search may occupy. */
    private const RESULT_SHARE = 0.06;

    /** Rough width of one match line, for turning a character budget into rows. */
    private const CHARS_PER_RESULT = 110;

    public function __construct(
        private readonly ProjectPathResolver $paths = new ProjectPathResolver(),
        // Optional so the tool stays constructible on its own; absent, the
        // floors above apply.
        private readonly ?ContextBudget $budget = null,
    ) {}

    /** How many match lines this window can afford from one search. */
    private function resultCap(): int
    {
        if ($this->budget === null) {
            return self::MIN_RESULTS;
        }

        $chars = $this->budget->shareInChars(
            self::RESULT_SHARE,
            self::MIN_RESULTS * self::CHARS_PER_RESULT,
            self::MAX_RESULTS * self::CHARS_PER_RESULT,
        );

        return max(self::MIN_RESULTS, intdiv($chars, self::CHARS_PER_RESULT));
    }

    /**
     * Matches per file, raised only once the overall cap is generous enough that
     * one crowded file cannot consume the whole budget on its own.
     */
    private function perFileCap(int $resultCap): int
    {
        return max(self::MIN_PER_FILE, min(self::MAX_PER_FILE, intdiv($resultCap, 20)));
    }

    public function __invoke(
        #[Param('Pattern to search for (regular expression supported)')] string $pattern,
        #[Param('Subdirectory or file to search in, relative to project root (optional, defaults to entire project)')] ?string $path = null,
        #[Param('File extension filter e.g. php, yaml, twig (optional)')] ?string $ext = null,
    ): string {
        $base = $this->paths->root();
        // A subdirectory argument is confined too — otherwise grep becomes a
        // read primitive for the whole filesystem at AUTO permission.
        $target = $path !== null ? $this->paths->resolve($path) : $base;

        $resultCap = $this->resultCap();

        $cmd = ['grep', '-rn', '--color=never', '-m', (string) $this->perFileCap($resultCap)];

        if ($ext !== null) {
            $cmd[] = '--include=*.' . ltrim($ext, '.');
        }

        $cmd[] = '--exclude-dir=vendor';
        $cmd[] = '--exclude-dir=node_modules';
        $cmd[] = '--exclude-dir=.git';
        $cmd[] = '--exclude-dir=var';
        $cmd[] = $pattern;
        $cmd[] = $target;

        $process = new Process($cmd, timeout: 15);
        $process->run();

        $output = trim($process->getOutput());

        if ($output === '') {
            return "No matches found for: {$pattern}";
        }

        // Make paths relative to project for readability
        $output = str_replace($base . '/', '', $output);

        $lines = explode("\n", $output);
        if (count($lines) > $resultCap) {
            $lines = array_slice($lines, 0, $resultCap);
            $lines[] = "... (truncated to {$resultCap} results)";
        }

        return implode("\n", $lines);
    }
}
