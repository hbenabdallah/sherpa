<?php

namespace App\Tool;

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
    private const MAX_OUTPUT_LINES = 100;
    private const MAX_OUTPUT_BYTES = 24000;

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
    ) {}

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
            return "Impossible d'exécuter dans le conteneur « {$this->dockerContainer} » : "
                . "docker n'est pas joignable depuis Sherpa, ou le conteneur ne tourne pas.\n"
                . 'Vérifiez que le conteneur est démarré, et que le client docker et son socket '
                . 'sont disponibles dans l\'image de Sherpa.';
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

            return "(commande interrompue après {$this->timeout}s)"
                . ($partial === '' ? '' : "\n" . $this->trim($partial));
        }

        $output = trim($process->getOutput() . $process->getErrorOutput());
        $code = $process->getExitCode();

        if ($output === '') {
            return $code === 0
                ? '(commande terminée sans sortie)'
                : "(commande échouée, code {$code}, sans sortie)";
        }

        // A failing command with output used to look exactly like a successful
        // one: the model read a test report full of failures as a job done.
        $status = $code === 0 ? '' : "(code de sortie {$code})\n";

        return $status . $this->trim($output);
    }

    /**
     * Output goes into the context window, so it is bounded twice: by lines,
     * because a hundred is already more than anyone reads, and by bytes,
     * because one line of a megabyte would blow the window on its own.
     */
    private function trim(string $output): string
    {
        if (strlen($output) > self::MAX_OUTPUT_BYTES) {
            $output = substr($output, 0, self::MAX_OUTPUT_BYTES) . "\n... (tronqué à " . self::MAX_OUTPUT_BYTES . ' octets)';
        }

        $lines = explode("\n", $output);
        if (count($lines) > self::MAX_OUTPUT_LINES) {
            $lines = array_slice($lines, 0, self::MAX_OUTPUT_LINES);
            $lines[] = '... (tronqué à ' . self::MAX_OUTPUT_LINES . ' lignes)';
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
     * The same directory, named the way the container names it.
     *
     * A host path handed to `docker exec -w` fails before the command starts:
     * nothing is mounted there inside the container. When the project is not
     * mounted at all, the container's own working directory is a better guess
     * than a path known to be wrong — and no -w at all is better than both.
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

            return "conteneur : {$this->dockerContainer}\n"
                . 'cwd : ' . ($inside ?? '(par défaut du conteneur)') . "\n\$ {$command}";
        }

        return "cwd : {$workDir}\n\$ {$command}";
    }
}
