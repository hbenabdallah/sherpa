<?php

namespace App\Tool;

use App\Agent\ContextBudget;
use App\Agent\Tool\AsTool;
use App\Agent\Tool\Param;
use App\Agent\Tool\Permission;
use App\Project\PathOutsideProjectException;
use App\Project\ProjectPathResolver;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

#[AsTool(
    name: 'shell_exec',
    description: 'Execute a shell command in the project directory. Use for running tests, checking routes, debugging, etc.',
    permission: Permission::CONFIRM,
)]
class ShellExecTool
{
    /**
     * Floors, not limits: a hundred lines is often fewer than the failures of a
     * test run occupy, and cutting the verdict off costs a whole turn to rerun
     * the suite. They scale with the window, and a small one is unaffected.
     */
    private const MIN_OUTPUT_LINES = 100;
    private const MIN_OUTPUT_BYTES = 24000;
    private const MAX_OUTPUT_BYTES = 200000;

    /** Share of the prompt window one command's output may occupy. */
    private const OUTPUT_SHARE = 0.10;

    /**
     * Bytes per line allowed before the line cap binds instead of the byte cap.
     * Deliberately generous: this is a guard against one pathological line, not
     * an estimate of an average one.
     */
    private const BYTES_PER_LINE = 240;

    private ?string $dockerContainer = null;

    /**
     * @param int $timeout Seconds a command may run. Long enough for a test
     *                     suite, short enough that a command waiting on input
     *                     nobody will ever type does not hold the session.
     */
    public function __construct(
        private readonly ProjectPathResolver $paths = new ProjectPathResolver(),
        private readonly ContainerInspector $containers = new ContainerInspector(),
        private readonly int $timeout = 300,
        // Optional so the tool stays constructible on its own — the confirm
        // overlay is built with a bare one in several tests. Absent, the floors
        // above apply, which is the behaviour this class had before the window
        // became a variable.
        private readonly ?ContextBudget $budget = null,
    ) {}

    /** How many bytes of output this window can afford from one command. */
    private function outputByteCap(): int
    {
        return $this->budget?->shareInChars(self::OUTPUT_SHARE, self::MIN_OUTPUT_BYTES, self::MAX_OUTPUT_BYTES)
            ?? self::MIN_OUTPUT_BYTES;
    }

    public function setDockerContainer(?string $container): void
    {
        $this->dockerContainer = $container;
    }

    public function __invoke(
        #[Param('Shell command to execute')] string $command,
        #[Param('Working directory relative to project root (optional)')] ?string $cwd = null,
    ): string {
        $workDir = $this->workDir($cwd);

        if ($this->dockerContainer !== null && $this->containers->inspect($this->dockerContainer) === null) {
            // Saying this plainly matters: the alternative is a Symfony Process
            // exception about a missing binary, or an OCI runtime error, for a
            // configuration problem the user can actually fix.
            return "Cannot run in container \"{$this->dockerContainer}\": "
                . "docker is not reachable from Sherpa, or the container is not running.\n"
                . 'Check that the container is started. If Sherpa itself runs in Docker, '
                . 'it has neither a docker client nor a socket: run it on the machine (make php).';
        }

        $fullCmd = $this->buildCommand($command, $workDir);

        // The resolved directory, which is already confined to the project. For a
        // Docker command it is also a valid host directory to start from, while
        // -w decides where the command actually lands inside the container.
        $process = new Process($fullCmd, cwd: $workDir, timeout: $this->timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // Whatever it printed before the deadline is usually the answer —
            // a test run that hangs at test 47 has already named test 47.
            $partial = trim($process->getOutput() . $process->getErrorOutput());

            return "(command interrupted after {$this->timeout}s)"
                . ($partial === '' ? '' : "\n" . $this->trim($partial));
        }

        $output = trim($process->getOutput() . $process->getErrorOutput());
        $code = $process->getExitCode();

        if ($output === '') {
            return $code === 0
                ? '(command finished with no output)'
                : "(command failed, code {$code}, no output)";
        }

        // Without the exit code, a failing command with output looks like a
        // successful one: the model reads a report full of failures as done.
        $status = $code === 0 ? '' : "(exit code {$code})\n";

        return $status . $this->trim($output);
    }

    /**
     * Output goes into the context window, so it is bounded twice: by bytes,
     * because one line of a megabyte would blow the window on its own, and by
     * lines, because past a point nobody — model included — is reading them.
     */
    private function trim(string $output): string
    {
        $byteCap = $this->outputByteCap();

        if (strlen($output) > $byteCap) {
            $output = substr($output, 0, $byteCap) . "\n... (truncated to " . $byteCap . ' bytes)';
        }

        $lineCap = max(self::MIN_OUTPUT_LINES, intdiv($byteCap, self::BYTES_PER_LINE));

        $lines = explode("\n", $output);
        if (count($lines) > $lineCap) {
            $lines = array_slice($lines, 0, $lineCap);
            $lines[] = '... (truncated to ' . $lineCap . ' lines)';
        }

        return implode("\n", $lines);
    }

    /**
     * The working directory is confined to the project. shell_exec is of course
     * still able to escape once running — that is what CONFIRM is for — but the
     * model cannot silently start the process somewhere else.
     */
    private function workDir(?string $cwd): string
    {
        return $cwd !== null ? $this->paths->resolve($cwd) : $this->paths->root();
    }

    /** @return string[] */
    private function buildCommand(string $command, string $workDir): array
    {
        if ($this->dockerContainer === null) {
            return ['bash', '-c', $command];
        }

        $inside = $this->containerWorkDir($workDir);

        return $inside === null
            ? ['docker', 'exec', '-i', $this->dockerContainer, 'bash', '-c', $command]
            : ['docker', 'exec', '-i', '-w', $inside, $this->dockerContainer, 'bash', '-c', $command];
    }

    /**
     * The same directory, named the way the container names it: a host path
     * handed to `docker exec -w` fails before the command starts. Unmounted,
     * the container's own working directory beats a path known to be wrong.
     */
    private function containerWorkDir(string $workDir): ?string
    {
        $container = $this->containers->inspect((string) $this->dockerContainer);

        if ($container === null) {
            return null;
        }

        return $this->containers->translate($container, $workDir) ?? $container['workdir'];
    }

    /**
     * The command exactly as it will be run, Docker wrapping included, so the
     * confirmation overlay shows what actually executes rather than the raw
     * string the model proposed.
     */
    public function describe(string $command, ?string $cwd = null): string
    {
        try {
            $workDir = $this->workDir($cwd);
        } catch (PathOutsideProjectException $e) {
            return '!! ' . $e->getMessage();
        }

        if ($this->dockerContainer !== null) {
            // The overlay exists to show what will actually run. Showing the
            // host path there would be showing something that does not happen.
            $inside = $this->containerWorkDir($workDir);

            return "container: {$this->dockerContainer}\n"
                . 'cwd: ' . ($inside ?? "(the container's default)") . "\n\$ {$command}";
        }

        return "cwd: {$workDir}\n\$ {$command}";
    }
}
