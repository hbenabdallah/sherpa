<?php

declare(strict_types=1);

namespace App\Project;

/**
 * How this project runs its tests, when it says so somewhere. The benchmark's
 * one genuine failure after file_patch was fixed: the model "checked" with a
 * file that only defines functions, took the silence for a pass, and left a
 * failing test behind — while the README named the real command.
 *
 * So it is looked up once and put in front of the model. Executable
 * declarations first (a composer script, a make target, a runner's config) and
 * the README last, since prose goes stale — but it is often the only place.
 */
class TestCommandDetector
{
    /** The first word of a line in a README that can be a test command. */
    private const COMMAND_STARTS = [
        'php', 'composer', 'make', 'vendor/bin/', 'bin/', './', 'npm', 'npx', 'yarn', 'pnpm', 'bun',
        'pytest', 'python', 'tox', 'go', 'cargo', 'bundle', 'rake', 'rspec', 'mix', 'dotnet',
        'mvn', 'gradle', './gradlew', 'deno',
    ];

    public function detect(string $projectPath): ?TestCommand
    {
        $path = rtrim($projectPath, '/');

        foreach ([
            fn() => $this->composer($path),
            fn() => $this->make($path),
            fn() => $this->node($path),
            fn() => $this->phpunit($path),
            fn() => is_file("{$path}/go.mod") ? new TestCommand('go test ./...', 'go.mod') : null,
            fn() => is_file("{$path}/Cargo.toml") ? new TestCommand('cargo test', 'Cargo.toml') : null,
            fn() => $this->pytest($path),
            fn() => $this->readme($path),
        ] as $probe) {
            $command = $probe();
            if ($command !== null) {
                return $command;
            }
        }

        return null;
    }

    private function composer(string $path): ?TestCommand
    {
        $scripts = $this->json("{$path}/composer.json")['scripts'] ?? null;

        return is_array($scripts) && isset($scripts['test'])
            ? new TestCommand('composer test', 'composer.json')
            : null;
    }

    private function make(string $path): ?TestCommand
    {
        $makefile = $this->read("{$path}/Makefile");

        return $makefile !== null && preg_match('/^test\s*:/m', $makefile) === 1
            ? new TestCommand('make test', 'Makefile')
            : null;
    }

    private function node(string $path): ?TestCommand
    {
        $test = $this->json("{$path}/package.json")['scripts']['test'] ?? null;

        // npm init writes a test script that only says there are no tests.
        return is_string($test) && !str_contains($test, 'no test specified')
            ? new TestCommand('npm test', 'package.json')
            : null;
    }

    private function phpunit(string $path): ?TestCommand
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'] as $config) {
            if (is_file("{$path}/{$config}")) {
                // Symfony's own wrapper when there is one: it sets up the
                // bridge the project's tests expect.
                return new TestCommand(is_file("{$path}/bin/phpunit") ? 'php bin/phpunit' : 'vendor/bin/phpunit', $config);
            }
        }

        return null;
    }

    private function pytest(string $path): ?TestCommand
    {
        if (is_file("{$path}/pytest.ini")) {
            return new TestCommand('pytest', 'pytest.ini');
        }

        $pyproject = $this->read("{$path}/pyproject.toml");

        return $pyproject !== null && str_contains($pyproject, '[tool.pytest')
            ? new TestCommand('pytest', 'pyproject.toml')
            : null;
    }

    /**
     * A command in a code block of the README that mentions tests.
     *
     * Code blocks only — fenced, or indented by four spaces — because that is
     * where READMEs put what is meant to be typed, and prose that merely
     * mentions "the tests" is not a command.
     */
    private function readme(string $path): ?TestCommand
    {
        foreach (['README.md', 'README', 'readme.md', 'README.rst', 'README.txt'] as $name) {
            $readme = $this->read("{$path}/{$name}");
            if ($readme === null) {
                continue;
            }

            $fenced = false;
            foreach (explode("\n", $readme) as $line) {
                if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                    $fenced = !$fenced;
                    continue;
                }

                if (!$fenced && preg_match('/^( {4}|\t)/', $line) !== 1) {
                    continue;
                }

                $command = $this->commandIn($line);
                if ($command !== null) {
                    return new TestCommand($command, $name);
                }
            }
        }

        return null;
    }

    private function commandIn(string $line): ?string
    {
        // Drop a shell prompt in front and a comment behind.
        $command = trim((string) preg_replace('/^\s*\$\s+/', '', $line));
        $command = trim((string) preg_replace('/\s+#.*$/', '', $command));

        if ($command === '' || preg_match('/\btests?\b|\bspec\b|phpunit|pytest|rspec/i', $command) !== 1) {
            return null;
        }

        foreach (self::COMMAND_STARTS as $start) {
            if (str_starts_with($command, $start)) {
                return $command;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function json(string $file): ?array
    {
        $content = $this->read($file);
        $data = $content === null ? null : json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    private function read(string $file): ?string
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $content = @file_get_contents($file, length: 200_000);

        return $content === false ? null : $content;
    }
}
