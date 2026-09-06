<?php

declare(strict_types=1);

namespace App\Project;

use Symfony\Component\Process\Process;

/**
 * What this project has been worked on lately, as a handful of keywords. The
 * prompt is built before anyone has asked anything, but that is not the same as
 * having no information: git says what was touched, and the launch directory
 * says where somebody is standing. A fact about src/Payment/ is worth quoting
 * in full on the morning someone works there. Retrieval whose query is the
 * situation, at no inference cost.
 */
final class RecentActivity
{
    /** Commits looked back through. Far enough for a week, short enough to stay cheap. */
    private const COMMITS = 20;

    /** Keywords returned at most; past this it stops discriminating. */
    private const MAX_TERMS = 25;

    /**
     * Path segments that name every project rather than this one, so boosting
     * on them would boost everything — which is the same as boosting nothing.
     */
    private const NOISE = [
        'src', 'tests', 'test', 'config', 'var', 'vendor', 'public', 'bin',
        'app', 'lib', 'index', 'node_modules', 'php', 'yaml', 'yml', 'json',
        'xml', 'twig', 'md', 'lock', 'dist', 'build', 'assets', 'templates',
        'migrations', 'resources', 'component', 'components',
    ];

    /**
     * @return string[] lowercase keywords, most recent activity first
     */
    public function terms(string $projectPath, ?string $launchDir = null): array
    {
        return $this->termsFrom($this->gitPaths($projectPath), $projectPath, $launchDir);
    }

    /**
     * The pure half, so the extraction can be tested without a repository.
     *
     * @param  string[] $paths project-relative paths git reported
     * @return string[]
     */
    public function termsFrom(array $paths, string $projectPath, ?string $launchDir = null): array
    {
        // The directory someone launched from is the strongest single signal
        // available, and it is free — so it goes in front of anything git said.
        if ($launchDir !== null) {
            $relative = $this->relative($launchDir, $projectPath);
            if ($relative !== null) {
                array_unshift($paths, $relative);
            }
        }

        $terms = [];

        foreach ($paths as $path) {
            foreach ($this->segments($path) as $segment) {
                if (count($terms) >= self::MAX_TERMS) {
                    break 2;
                }
                $terms[$segment] = true;
            }
        }

        return array_keys($terms);
    }

    /**
     * Segments of one path worth matching on: directory names and the file's
     * own name without extension, minus the words every project shares.
     *
     * @return string[]
     */
    private function segments(string $path): array
    {
        $out = [];

        foreach (explode('/', trim($path, '/')) as $raw) {
            // Extension dropped, case kept: the split below needs the capitals.
            $raw = preg_replace('/\.[A-Za-z0-9]+$/', '', $raw) ?? $raw;
            $segment = mb_strtolower($raw);

            if (mb_strlen($segment) < 3 || in_array($segment, self::NOISE, true)) {
                continue;
            }

            $out[] = $segment;

            // CamelCase and snake_case both carry the domain word inside them:
            // StripeGateway should match a fact that only says "stripe". Split
            // the original spelling — lowercasing first erases the very
            // boundary this looks for, which is how "stripegateway" ends up as
            // the only term and matches nothing.
            foreach (preg_split('/[_\-]|(?<=[a-z])(?=[A-Z])/', $raw) ?: [] as $word) {
                $word = mb_strtolower($word);
                if ($word !== $segment && mb_strlen($word) >= 4 && !in_array($word, self::NOISE, true)) {
                    $out[] = $word;
                }
            }
        }

        return $out;
    }

    /** $launchDir expressed relative to the project, or null if outside it. */
    private function relative(string $launchDir, string $projectPath): ?string
    {
        $launchDir = rtrim($launchDir, '/');
        $projectPath = rtrim($projectPath, '/');

        if ($launchDir === $projectPath || !str_starts_with($launchDir, $projectPath . '/')) {
            return null;
        }

        return substr($launchDir, strlen($projectPath) + 1);
    }

    /**
     * Paths git has touched recently, uncommitted first. Silence on failure is
     * deliberate: this is a ranking hint, and a project that is not a
     * repository must cost the digest its boost and nothing else.
     *
     * @return string[]
     */
    private function gitPaths(string $projectPath): array
    {
        if (!is_dir($projectPath . '/.git')) {
            return [];
        }

        $paths = [];

        // Uncommitted work outranks committed work: it is what is open now.
        foreach ($this->git($projectPath, ['status', '--porcelain']) as $line) {
            $path = trim(mb_substr($line, 3));
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        foreach ($this->git($projectPath, ['log', '--name-only', '--pretty=format:', '-n', (string) self::COMMITS]) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $paths[] = $line;
            }
        }

        return $paths;
    }

    /**
     * @param  string[] $arguments
     * @return string[] output lines, empty on any failure
     */
    private function git(string $projectPath, array $arguments): array
    {
        try {
            $process = new Process(['git', '-C', $projectPath, ...$arguments], timeout: 5);
            $process->run();

            if (!$process->isSuccessful()) {
                return [];
            }

            return explode("\n", trim($process->getOutput()));
        } catch (\Throwable) {
            return [];
        }
    }
}
