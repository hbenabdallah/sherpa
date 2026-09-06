<?php

declare(strict_types=1);

namespace App\Rag;

use Symfony\Component\Process\Process;

/**
 * Which files of a project are its documentation. Asked of git first, since
 * what .gitignore excludes — build output, generated API docs, a vendored
 * README — is not this project's, and untracked files are included because a
 * document written today counts too. Without git, a walk that skips the
 * directories every stack fills with other people's files.
 */
final class DocSource
{
    public const EXTENSIONS = ['md', 'markdown', 'mdx', 'txt', 'text', 'rst', 'adoc', 'asciidoc', 'html', 'htm'];

    /**
     * Never documentation of this project, whatever git says. Test fixtures
     * included: a fixture README describes a pretend project, and answering
     * from it would be answering about something that does not exist.
     */
    private const SKIPPED = ['.git', 'vendor', 'node_modules', 'var', 'build', 'dist', 'coverage', '.cache', '.idea', '.vscode', 'tmp',
        'fixtures', '__fixtures__', 'testdata'];

    /**
     * Files whose extension says "text" and whose name says otherwise: they
     * configure a tool, they do not document the project.
     */
    private const NOT_DOCUMENTATION = '/^(robots|humans|security|requirements.*|constraints.*|cmakelists|third[-_]party[-_]notices)\.txt$/i';

    /**
     * Where HTML and plain text are the application, not its documentation:
     * a page served from public/, a template, an asset. Markdown is left alone
     * everywhere — a README in src/ is documentation wherever it sits.
     */
    private const APPLICATION_DIRS = ['public', 'static', 'assets', 'templates', 'resources', 'web', 'www', 'src', 'app', 'lib'];

    /** A file this large is generated, not written — and would drown the rest. */
    public const MAX_BYTES = 1_000_000;

    /** @return list<string> paths relative to $root, sorted */
    public function files(string $root): array
    {
        $listed = $this->fromGit($root) ?? $this->walk($root);

        $files = array_values(array_filter($listed, function (string $path) use ($root): bool {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($extension, self::EXTENSIONS, true) || preg_match(self::NOT_DOCUMENTATION, basename($path)) === 1) {
                return false;
            }
            if (in_array($extension, ['html', 'htm', 'txt', 'text'], true)
                && array_intersect(array_slice(explode('/', $path), 0, -1), self::APPLICATION_DIRS) !== []) {
                return false;
            }
            foreach (explode('/', $path) as $part) {
                if (in_array($part, self::SKIPPED, true)) {
                    return false;
                }
            }
            $full = $root . '/' . $path;

            return is_file($full) && filesize($full) <= self::MAX_BYTES;
        }));

        sort($files);

        return $files;
    }

    /** @return list<string>|null null when $root is not inside a git repository */
    private function fromGit(string $root): ?array
    {
        try {
            $process = new Process(['git', '-C', $root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], timeout: 20);
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        return array_values(array_filter(explode("\0", $process->getOutput()), static fn(string $p) => $p !== ''));
    }

    /** @return list<string> */
    private function walk(string $root): array
    {
        $files = [];
        $directories = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $file) => !($file->isDir() && in_array($file->getFilename(), self::SKIPPED, true)),
        );

        foreach (new \RecursiveIteratorIterator($directories) as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        return $files;
    }
}
