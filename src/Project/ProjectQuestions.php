<?php

declare(strict_types=1);

namespace App\Project;

use App\TUI\LineEditor;
use App\TUI\Terminal;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The questions a new directory gets, once: its name, and whether its commands
 * run in a container. No path question — the project is the launch directory —
 * and every question has an answer ready, so Enter all the way through is a
 * valid setup. Nothing is written: the answers make a draft.
 */
class ProjectQuestions
{
    /** Service names that usually hold the application and its database. */
    private const array APP_HINTS = ['app', 'php', 'web', 'api', 'backend'];

    private const array DB_HINTS = ['db', 'database', 'mysql', 'mariadb', 'postgres', 'pgsql'];

    public function __construct(
        private readonly LineEditor $editor,
        private readonly ProjectStore $store,
    ) {}

    public function ask(string $path): Project
    {
        echo "\n" . Terminal::BOLD . Terminal::GREEN . '  New directory: ' . Terminal::RESET . $path . "\n";
        echo Terminal::GRAY . "  Two questions, once. Enter keeps what is offered.\n\n" . Terminal::RESET;

        // The directory's name when nothing is typed — never an empty name.
        $name = $this->askWithDefault('  Project name', basename($path)) ?: basename($path);
        $docker = $this->askDocker($path, null);

        return $this->store->draft($path, $name, $docker);
    }

    /** /project edit: the same questions, the current answers as defaults. */
    public function edit(Project $project): Project
    {
        echo "\n" . Terminal::BOLD . '  Edit "' . $project->name . "\"\n" . Terminal::RESET;
        echo Terminal::GRAY . "  Enter keeps the current value.\n\n" . Terminal::RESET;

        $name = $this->askWithDefault('  Project name', $project->name) ?: $project->name;
        $docker = $this->askDocker($project->path, $project->docker);

        return $this->store->update($project, $name, $project->path, $docker);
    }

    /**
     * The one question before something irreversible, with what it costs in
     * numbers: "forget the project" means nothing until it says how many facts
     * and conversations go with it. No by default, as everywhere else in Sherpa
     * where something is destroyed: Enter alone keeps everything.
     */
    public function confirmForget(Project $project, int $facts, int $conversations): bool
    {
        echo "\n" . Terminal::BOLD . Terminal::YELLOW . "  Forget \"{$project->name}\"?\n" . Terminal::RESET;
        echo '  Deleted: ' . $facts . ' remembered fact' . ($facts > 1 ? 's' : '')
            . ', ' . $conversations . ' conversation' . ($conversations > 1 ? 's' : '')
            . ", the standing grants.\n";
        echo Terminal::GRAY . "  The directory {$project->path} is not touched. The session ends after this.\n\n" . Terminal::RESET;

        $answer = strtolower($this->editor->ask('  Forget this project? [y/N]: ', Terminal::BOLD));

        return in_array($answer, ['o', 'oui', 'y', 'yes'], true);
    }

    /**
     * Docker for a directory nobody can be asked about — opened from an
     * editor: what the terminal would offer with Enter, without the
     * questions. On only when the directory's containers are running, since a
     * stopped one runs nothing; said either way.
     *
     * @return array{DockerConfig, string} the setting, and what to tell the user of it
     */
    public function guessDocker(string $path): array
    {
        $running = $this->runningContainers($path);
        $wrapper = $running !== [] || $this->hasCompose($path) ? DockerWrapper::in($path) : null;
        $container = $wrapper === null ? $this->pick($running, self::APP_HINTS) : null;
        $change = 'To change it: sherpa in a terminal, in this directory, then /project edit.';

        if ($container !== null) {
            return [
                new DockerConfig(enabled: true, container: $container, dbContainer: $this->pick($running, self::DB_HINTS, required: false) ?? '', user: DockerConfig::USER_HOST),
                "New project: its commands run in the container {$container}, which is running. {$change}",
            ];
        }

        return [new DockerConfig(enabled: false), 'New project: its commands run on this machine' . match (true) {
            $wrapper !== null => ", its {$wrapper} going through docker itself. ",
            $running !== []   => ': none of its running containers looks like the app\'s. ',
            $this->hasCompose($path) => ': it has a docker compose file, but none of its containers is running. ',
            default => '. ',
        } . $change];
    }

    private function askDocker(string $path, ?DockerConfig $current): DockerConfig
    {
        $running = $this->runningContainers($path);
        $composed = $this->hasCompose($path);

        // Yes by default when the directory says it runs in containers: a
        // compose file, or containers named after it already running. Unless
        // its Makefile already goes through docker itself: `make test` then
        // works from this machine, and inside the container — with no docker
        // client there — it cannot work at all.
        $wrapper = ($composed || $running !== []) ? DockerWrapper::in($path) : null;
        $likely = $current->enabled ?? (($composed || $running !== []) && $wrapper === null);

        if (!$current instanceof DockerConfig && ($composed || $running !== [])) {
            echo Terminal::GRAY . '  ' . ($running !== []
                ? 'Containers of this directory are running: ' . implode(', ', $running)
                : 'A docker compose file is here.') . "\n" . Terminal::RESET;
            if ($wrapper !== null) {
                echo Terminal::GRAY . "  But its {$wrapper} already runs commands through docker: they run from this machine.\n" . Terminal::RESET;
            }
        }

        $answer = strtolower($this->editor->ask('  Docker environment? [' . ($likely ? 'Y/n' : 'y/N') . ']: ', Terminal::BOLD));
        $enabled = $answer === '' ? $likely : in_array($answer, ['o', 'oui', 'y', 'yes'], true);

        if (!$enabled) {
            return new DockerConfig(enabled: false);
        }

        $container = $this->askWithDefault('  App container', $current?->container ?: $this->pick($running, self::APP_HINTS));

        // Docker without a container to run in is no setting at all. Said, and
        // turned off, rather than asked again: a question that repeats until it
        // gets an answer never ends on Ctrl+D.
        if ($container === '') {
            echo Terminal::GRAY . "  No container: Docker turned off (/project edit to come back to it).\n" . Terminal::RESET;

            return new DockerConfig(enabled: false);
        }

        return new DockerConfig(
            enabled: true,
            container: $container,
            dbContainer: $this->askWithDefault('  DB container (optional)', $current?->dbContainer ?: $this->pick($running, self::DB_HINTS, required: false)),
            // Not asked: the default is right almost everywhere, and the rest
            // is one line of projects.yaml.
            user: $current->user ?? DockerConfig::USER_HOST,
        );
    }

    /** The answer, or the default when there is one, or an empty string. Asked once. */
    private function askWithDefault(string $label, ?string $default): string
    {
        $shown = $default !== null && $default !== '' ? $label . ' [' . mb_substr($default, 0, 48) . ']: ' : $label . ': ';
        $answer = $this->editor->ask($shown, Terminal::BOLD);

        return $answer !== '' ? $answer : (string) $default;
    }

    /**
     * Containers running under this directory's compose project: real names, so
     * offering one costs nothing, where an invented default would be accepted
     * with Enter and fail at the first command. Compose names them after the
     * directory, which is how they are recognised.
     *
     * @return list<string>
     */
    private function runningContainers(string $path): array
    {
        $prefix = strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', basename($path))) . '-';

        try {
            $process = new Process(['docker', 'ps', '--format', '{{.Names}}'], timeout: 3);
            $process->run();
        } catch (Throwable) {
            return [];
        }

        if (!$process->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode("\n", $process->getOutput())),
            static fn(string $name): bool => $name !== '' && str_starts_with(strtolower($name), $prefix),
        ));
    }

    /**
     * @param list<string> $containers
     * @param list<string> $hints
     */
    private function pick(array $containers, array $hints, bool $required = true): ?string
    {
        foreach ($hints as $hint) {
            foreach ($containers as $name) {
                if (preg_match('/[-_]' . preg_quote($hint, '/') . '([-_]\d+)?$/i', $name) === 1) {
                    return $name;
                }
            }
        }

        // One container and nothing better to go on: it is the app, not the database.
        return $required && count($containers) === 1 ? $containers[0] : null;
    }

    private function hasCompose(string $path): bool
    {
        return array_any(['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'], fn(string $file): bool => is_file($path . '/' . $file));
    }
}
