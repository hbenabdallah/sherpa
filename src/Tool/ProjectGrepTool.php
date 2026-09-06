<?php

namespace App\Tool;

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
    public function __construct(
        private readonly ProjectPathResolver $paths = new ProjectPathResolver(),
    ) {}

    public function __invoke(
        #[Param('Pattern to search for (regular expression supported)')] string $pattern,
        #[Param('Subdirectory or file to search in, relative to project root (optional, defaults to entire project)')] ?string $path = null,
        #[Param('File extension filter e.g. php, yaml, twig (optional)')] ?string $ext = null,
    ): string {
        $base = $this->paths->root();
        // A subdirectory argument is confined too — otherwise grep becomes a
        // read primitive for the whole filesystem at AUTO permission.
        $target = $path !== null ? $this->paths->resolve($path) : $base;

        $cmd = ['grep', '-rn', '--color=never', '-m', '5'];

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
        if (count($lines) > 50) {
            $lines = array_slice($lines, 0, 50);
            $lines[] = '... (truncated to 50 results)';
        }

        return implode("\n", $lines);
    }
}
