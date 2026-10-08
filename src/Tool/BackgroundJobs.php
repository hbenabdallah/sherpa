<?php

declare(strict_types=1);

namespace App\Tool;

use Symfony\Component\Process\Process;

/**
 * Commands left running while the conversation goes on — a dev server, a
 * watcher, a test suite that takes ten minutes — read when there is something
 * to read, stopped when asked, and all stopped when Sherpa exits.
 *
 * Stopping has to reach what the command started, not only the shell around
 * it: `npm run dev` is a tree of processes. On the machine, a job gets a
 * process group of its own (setsid, or perl's where there is no setsid, on
 * macOS) and the whole group is signalled. In a project's container, the
 * command's pid is written down inside it and signalled there: stopping
 * `docker exec` alone leaves the process running in the container.
 */
final class BackgroundJobs
{
    /** How long a job is watched at start, to report an early failure or a first line. */
    public const FIRST_LOOK_SECONDS = 3.0;

    /** @var array<int, array{command: string, process: Process, started: float, container: ?string, inContainer: callable(string): list<string>, group: bool, stopped: bool}> */
    private array $jobs = [];

    private int $next = 1;

    /**
     * @param string                        $shown       the command as the model wrote it, for /jobs
     * @param string                        $script      what bash -c runs
     * @param string|null                   $container   the project's container, when it runs there
     * @param callable(string): list<string> $inContainer builds the docker exec around a script
     */
    public function start(string $shown, string $script, string $cwd, ?string $container, callable $inContainer): int
    {
        $id = $this->next++;

        if ($container !== null) {
            // Its own pid, noted where the stop can find it, then replaced by
            // the command itself.
            $wrapped = 'echo $$ > ' . self::pidFile($id) . '; exec bash -c ' . escapeshellarg($script);
            $command = $inContainer($wrapped);
            $group = false;
        } else {
            [$command, $group] = self::ownGroup($script);
        }

        $process = new Process($command, cwd: $cwd, timeout: null);
        $process->start();

        $this->jobs[$id] = ['command' => $shown, 'process' => $process, 'started' => microtime(true), 'container' => $container, 'inContainer' => $inContainer, 'group' => $group, 'stopped' => false];

        return $id;
    }

    /**
     * What the job printed since the last read, and where it stands.
     *
     * @return array{output: string, running: bool, exitCode: ?int, seconds: int}
     */
    public function read(int $id): array
    {
        $job = $this->job($id);
        $process = $job['process'];

        $output = $process->getIncrementalOutput() . $process->getIncrementalErrorOutput();

        return [
            'output'   => $output,
            'running'  => $process->isRunning(),
            'exitCode' => $process->isRunning() ? null : $process->getExitCode(),
            'seconds'  => (int) (microtime(true) - $job['started']),
        ];
    }

    /** Wait up to $seconds for the job to end or say something. */
    public function firstLook(int $id, float $seconds = self::FIRST_LOOK_SECONDS): void
    {
        $process = $this->job($id)['process'];
        $until = microtime(true) + $seconds;

        while (microtime(true) < $until && $process->isRunning()) {
            usleep(100_000);
        }
    }

    /** @return string what happened, to say so */
    public function stop(int $id): string
    {
        $job = $this->job($id);
        $process = $job['process'];

        if (!$process->isRunning()) {
            return "job {$id} had already ended (exit code " . $process->getExitCode() . ').';
        }

        if ($job['container'] !== null) {
            // As the user the job runs as: another one may not be allowed to
            // signal it, nor to remove the pid file it wrote.
            $kill = new Process(($job['inContainer'])(
                'kill -TERM "$(cat ' . self::pidFile($id) . ')" 2>/dev/null; rm -f ' . self::pidFile($id),
            ), timeout: 10);
            $kill->run();
        } elseif ($job['group'] && function_exists('posix_kill') && ($pid = $process->getPid()) !== null) {
            // The whole group: the shell and everything it started.
            @posix_kill(-$pid, SIGTERM);
        }

        $process->stop(3);
        $this->jobs[$id]['stopped'] = true;

        return "job {$id} stopped.";
    }

    public function stopAll(): void
    {
        foreach (array_keys($this->jobs) as $id) {
            if ($this->jobs[$id]['process']->isRunning()) {
                $this->stop($id);
            }
        }
    }

    /**
     * @return list<array{id: int, command: string, running: bool, exitCode: ?int, seconds: int, stopped: bool}>
     */
    public function all(): array
    {
        $list = [];
        foreach ($this->jobs as $id => $job) {
            $running = $job['process']->isRunning();
            $list[] = [
                'id'       => $id,
                'command'  => $job['command'],
                'running'  => $running,
                'exitCode' => $running ? null : $job['process']->getExitCode(),
                'seconds'  => (int) (microtime(true) - $job['started']),
                'stopped'  => $job['stopped'],
            ];
        }

        return $list;
    }

    /** @return array{0: list<string>, 1: bool} the command, and whether it has a group of its own */
    private static function ownGroup(string $script): array
    {
        if (self::onPath('setsid')) {
            return [['setsid', 'bash', '-c', $script], true];
        }
        if (self::onPath('perl')) {
            return [['perl', '-e', 'use POSIX qw(setsid); setsid(); exec @ARGV or die', 'bash', '-c', $script], true];
        }

        return [['bash', '-c', $script], false];
    }

    private static function onPath(string $command): bool
    {
        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable("{$dir}/{$command}")) {
                return true;
            }
        }

        return false;
    }

    private static function pidFile(int $id): string
    {
        return '/tmp/sherpa-job-' . getmypid() . "-{$id}.pid";
    }

    /** @return array{command: string, process: Process, started: float, container: ?string, inContainer: callable(string): list<string>, group: bool, stopped: bool} */
    private function job(int $id): array
    {
        return $this->jobs[$id] ?? throw new \RuntimeException("no job {$id}: /jobs lists them, and ids are given when a job starts.");
    }

    public function __destruct()
    {
        $this->stopAll();
    }
}
