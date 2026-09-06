<?php

namespace App\Tool;

use App\Agent\ContextBudget;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Memory\MemoryStore;
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

    /** Longest match line shown whole; past it, the part around the match. */
    private const MAX_LINE_CHARS = 300;

    public function __construct(
        private readonly ProjectPathResolver $paths = new ProjectPathResolver(),
        // Optional so the tool stays constructible on its own; absent, the
        // floors above apply.
        private readonly ?ContextBudget $budget = null,
        // Counts hits and misses. The code half of the retrieval question had
        // no instrument at all, so it could only be argued about.
        private readonly ?MemoryStore $store = null,
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
        #[Param('Extended regular expression, as grep -E reads it: foo|bar, (get|set)Name, [0-9]+. Use [0-9] rather than \\d')] string $pattern,
        #[Param('Subdirectory or file to search in, relative to project root (optional, defaults to entire project)')] ?string $path = null,
        #[Param('File extension filter e.g. php, yaml, env (optional); also matches files where it is followed by another, like .env.example or phpunit.xml.dist')] ?string $ext = null,
    ): string {
        $base = $this->paths->root();
        // A subdirectory argument is confined too — otherwise grep becomes a
        // read primitive for the whole filesystem at AUTO permission.
        $target = $path !== null ? $this->paths->resolve($path) : $base;

        $resultCap = $this->resultCap();

        // -E: the regex dialect models actually write. In grep's default one,
        // `create|register` searches for a literal pipe, finds nothing, and the
        // model concludes the code does not exist — then the miss counter
        // records a vocabulary problem that was a syntax one.
        $cmd = ['grep', '-rnE', '--color=never', '-m', (string) $this->perFileCap($resultCap)];

        // `ext: env` has to find .env.example, and `ext: xml` phpunit.xml.dist:
        // configuration ships as a template far more often than as the file
        // itself, and a filter that silently skips it reads as "not there".
        if ($ext !== null && trim($ext, ". \t") !== '') {
            $ext = trim($ext, ". \t");
            $cmd[] = '--include=*.' . $ext;
            $cmd[] = '--include=*.' . $ext . '.*';
            $cmd[] = '--include=.' . $ext;
        }

        $cmd[] = '--exclude-dir=vendor';
        $cmd[] = '--exclude-dir=node_modules';
        $cmd[] = '--exclude-dir=.git';
        $cmd[] = '--exclude-dir=var';
        // -e, so a pattern that starts with a dash is a pattern and not an
        // option: "-f /etc/shadow" must not become grep reading its patterns
        // from a file outside the project.
        $cmd[] = '-e';
        $cmd[] = $pattern;
        $cmd[] = $target;

        $process = new Process($cmd, timeout: 15);
        $process->run();

        $output = trim($process->getOutput());

        // Exit status 2 is grep failing, not grep finding nothing — an
        // unbalanced parenthesis, typically. Said as such, so the model fixes
        // the pattern instead of concluding the code is not there, and not
        // counted as a miss, which would measure a typo as a vocabulary gap.
        if ($output === '' && $process->getExitCode() === 2) {
            $reason = trim($process->getErrorOutput());

            return 'Invalid search pattern: ' . $pattern . ($reason === '' ? '' : " ({$reason})")
                . '. Patterns are extended regular expressions (grep -E).';
        }

        if ($output === '') {
            $this->store?->bump('grep_misses');

            return "No matches found for: {$pattern}";
        }

        $this->store?->bump('grep_hits');

        // Make paths relative to project for readability
        $output = str_replace($base . '/', '', $output);

        // Bounded by lines and by characters: a line count alone let one
        // match inside minified JSON bring a whole megabyte into the window.
        $charCap = $resultCap * self::CHARS_PER_RESULT;
        $lines = explode("\n", $output);
        $shown = [];
        $chars = 0;
        foreach ($lines as $line) {
            if (preg_match('/^(.*?:\d+:)(.*)$/s', $line, $parts) === 1) {
                $line = $parts[1] . LongLine::clip($parts[2], self::MAX_LINE_CHARS, $pattern);
            }
            if (count($shown) === $resultCap || ($shown !== [] && $chars + strlen($line) > $charCap)) {
                $shown[] = sprintf('... (truncated: %d of %d results shown)', count($shown), count($lines));
                break;
            }
            $shown[] = $line;
            $chars += strlen($line) + 1;
        }

        return implode("\n", $shown);
    }
}
