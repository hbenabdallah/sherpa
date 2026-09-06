<?php

namespace App\Tool;

use App\Agent\ContextBudget;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Project\PathSuggestions;
use App\Project\ProjectPathResolver;
use Symfony\Component\Process\Process;

#[AsTool(
    name: 'file_find',
    description: 'Find files by name pattern: "*Test.php" anywhere, "src/**/*.yaml" under src, "{README,CHANGELOG}.md". The most recently changed come first. Use it to locate files by name; use project_grep to search inside them.',
    permission: Permission::AUTO,
)]
class FileFindTool
{
    /** Walked past when the project is not a git repository, as list_dir does. */
    private const SKIPPED = ['vendor', 'node_modules', '.git', 'var', '.idea', '.vscode', '__pycache__', '.venv', 'target'];

    /** Floors and ceiling on paths listed, scaled with the window like list_dir's lines. */
    private const MIN_PATHS = 100;
    private const MAX_PATHS = 1000;
    private const SHARE = 0.04;
    private const CHARS_PER_PATH = 50;

    public function __construct(
        private readonly ProjectPathResolver $paths = new ProjectPathResolver(),
        private readonly ?ContextBudget $budget = null,
    ) {}

    public function __invoke(
        #[Param('Glob pattern: * within a directory, ** across directories, ?, {a,b}, [abc]. Without a "/", it matches file names anywhere')] string $pattern,
        #[Param('Directory to search in, relative to the project root (optional, defaults to the root)')] ?string $path = null,
    ): string {
        $pattern = trim($pattern);
        if ($pattern === '') {
            throw new \RuntimeException('an empty pattern finds nothing: give one, like "*Test.php" or "src/**/*.php"');
        }

        $root = $this->paths->root();
        $base = $path === null || trim($path) === '' || trim($path) === '.' ? $root : $this->paths->resolve($path);
        if (!is_dir($base)) {
            throw new \RuntimeException("directory not found: {$path}" . PathSuggestions::hint($root, (string) $path, directory: true));
        }

        $regex = self::toRegex(str_contains($pattern, '/') ? ltrim($pattern, '/') : '**/' . $pattern);
        $prefix = $base === $root ? '' : substr($base, strlen($root) + 1) . '/';

        $found = [];
        foreach ($this->files($base) as $relative) {
            if (preg_match($regex, $relative) === 1) {
                $found[$prefix . $relative] = (int) @filemtime("{$base}/{$relative}");
            }
        }

        if ($found === []) {
            $where = $prefix === '' ? '' : " in {$prefix}";

            return "No file matches \"{$pattern}\"{$where}."
                . (str_contains($pattern, '/') && !str_contains($pattern, '**') ? ' A "*" stays within one directory: "**" goes through them.' : '');
        }

        // Most recently changed first: what someone is working on.
        uksort($found, fn(string $a, string $b) => [$found[$b], $a] <=> [$found[$a], $b]);
        $paths = array_keys($found);

        $cap = $this->cap();
        $shown = array_slice($paths, 0, $cap);
        $more = count($paths) - count($shown);

        return count($paths) . ' file' . (count($paths) > 1 ? 's' : '') . " match \"{$pattern}\":\n" . implode("\n", $shown)
            . ($more > 0 ? "\n… and {$more} more: narrow the pattern or the path." : '');
    }

    /**
     * A glob as an anchored regex over paths relative to where the search
     * starts. "**\/" is any number of directories, none included.
     */
    public static function toRegex(string $glob): string
    {
        $out = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];

            if (str_starts_with(substr($glob, $i), '**/')) {
                $out .= '(?:.*/)?';
                $i += 2;
            } elseif (str_starts_with(substr($glob, $i), '**')) {
                $out .= '.*';
                $i++;
            } elseif ($char === '*') {
                $out .= '[^/]*';
            } elseif ($char === '?') {
                $out .= '[^/]';
            } elseif ($char === '{' && ($close = strpos($glob, '}', $i)) !== false) {
                $options = explode(',', substr($glob, $i + 1, $close - $i - 1));
                $out .= '(?:' . implode('|', array_map(fn(string $o) => self::toRegexBody($o), $options)) . ')';
                $i = $close;
            } elseif ($char === '[' && ($close = strpos($glob, ']', $i + 1)) !== false) {
                $class = substr($glob, $i + 1, $close - $i - 1);
                $out .= '[' . (str_starts_with($class, '!') ? '^' . substr($class, 1) : $class) . ']';
                $i = $close;
            } else {
                $out .= preg_quote($char, '#');
            }
        }

        return '#^' . $out . '$#';
    }

    private static function toRegexBody(string $glob): string
    {
        return substr(self::toRegex($glob), 2, -2);
    }

    /**
     * Every file under $base, relative to it: git's list when there is one,
     * which leaves out whatever .gitignore does; the disk otherwise.
     *
     * @return list<string>
     */
    private function files(string $base): array
    {
        try {
            $git = new Process(['git', '-C', $base, 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], timeout: 20);
            $git->run();
            if ($git->isSuccessful()) {
                return array_values(array_filter(
                    explode("\0", $git->getOutput()),
                    fn(string $p) => $p !== '' && is_file("{$base}/{$p}"),
                ));
            }
        } catch (\Throwable) {
            // No git: the disk it is.
        }

        $files = [];
        $directories = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $file) => !($file->isDir() && in_array($file->getFilename(), self::SKIPPED, true)),
        );
        foreach (new \RecursiveIteratorIterator($directories) as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($base) + 1);
            }
        }

        return $files;
    }

    private function cap(): int
    {
        if ($this->budget === null) {
            return self::MIN_PATHS;
        }

        return intdiv($this->budget->shareInChars(self::SHARE, self::MIN_PATHS * self::CHARS_PER_PATH, self::MAX_PATHS * self::CHARS_PER_PATH), self::CHARS_PER_PATH);
    }
}
