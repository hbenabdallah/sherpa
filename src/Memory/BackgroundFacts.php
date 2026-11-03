<?php

declare(strict_types=1);

namespace App\Memory;

use App\Agent\MessageBag;
use App\Platform\SwitchablePlatform;
use App\Runtime\Interrupt;
use App\Usage\UsageMeter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The fact pass in a process of its own, so nobody waits for it: neither the
 * session, which goes on while it reads, nor someone leaving, who gets their
 * shell back at once.
 *
 * A fork, rather than a fresh Sherpa, keeps the provider, model and key the
 * session has now, whatever /provider or /model changed. What the two
 * processes would otherwise share, the child leaves alone and never closes:
 * it asks the model through a client of its own, and writes through a
 * database connection of its own. A pooled connection is not given up by
 * resetting the session's client (curl keeps it, in a share that outlives
 * the reset on PHP 8.5), and one the child reused was cut under it by the
 * session's next request: "SSL_read: unexpected eof".
 *
 * What a pass spent comes back over a socket pair, and counts in the
 * session's usage (its summary, its cap) once the pass is reaped.
 */
final class BackgroundFacts
{
    private const int WAIT_STEP_US = 50_000;

    /**
     * The child's 0, 1 and 2, held so they stay open.
     *
     * @var list<resource|false>
     */
    private array $stdio = [];

    /**
     * The passes under way, each with the end of the socket it reports on.
     *
     * @var array<int, resource>
     */
    private array $children = [];

    public function __construct(
        private readonly FactExtractor $extractor,
        private readonly MemoryStore $store,
        private readonly SwitchablePlatform $platform,
        /**
         * The child's own, never used by the session: its pool is empty when
         * a child inherits it. services.yaml gives it a client of its own.
         */
        private readonly HttpClientInterface $http,
        private readonly Interrupt $interrupt,
        private readonly UsageMeter $usage,
    ) {}

    /**
     * Whether a pass started now would see its end. Not in a container: the
     * container stops when Sherpa does, and every process in it goes too.
     */
    public function survivesExit(): bool
    {
        return !file_exists('/.dockerenv') && !file_exists('/run/.containerenv');
    }

    /** Until the passes under way end, or Ctrl+C. */
    public function wait(): void
    {
        while ($this->isRunning() && !$this->interrupt->requested()) {
            usleep(self::WAIT_STEP_US);
        }
    }

    /** Whether a pass is under way, once the ones that ended are reaped. */
    public function isRunning(): bool
    {
        $this->reap();

        return $this->children !== [];
    }

    /**
     * The passes that ended: their usage counted, their socket closed, their
     * process gone rather than left a zombie. Called often enough by whoever
     * runs for long: each turn in the terminal, each request in an editor.
     */
    public function reap(): void
    {
        foreach ($this->children as $pid => $report) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                continue;
            }

            $this->countUsage($report);
            unset($this->children[$pid]);
        }
    }

    /**
     * Read $bag from message $from on, in the background. False when it
     * cannot be: a project not saved yet has its memory in this process's
     * RAM, out of a child's reach; or the fork failed. The caller then does
     * it here, or not at all.
     */
    public function start(MessageBag $bag, int $from): bool
    {
        $database = $this->store->database();
        if ($database === null || $database === ':memory:' || !function_exists('pcntl_fork')) {
            return false;
        }

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            return false;
        }
        [$ours, $theirs] = $sockets;

        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($ours);
            fclose($theirs);

            return false;
        }

        if ($pid > 0) {
            // Ours alone holds it now, so reading it ends when the child does.
            fclose($theirs);
            $this->children[$pid] = $ours;

            return true;
        }

        // Whatever happens, this process ends here and only here: an
        // exception left to unwind would run the session's own code in it,
        // then the session's shutdown function, which hands the tty back and
        // stops the MCP servers and jobs under the session still using them.
        // detach() too: under the runtime's error handler, a failed fopen()
        // throws. Nothing is left to tell either: the pass reports to a
        // terminal this process no longer has.
        try {
            if ($this->detach()) {
                $this->extract($bag, $from, $database, $theirs);
            }
        } finally {
            self::vanish();
        }
    }

    /**
     * The pass, then what it spent, on $report. A pass that asked nothing
     * leaves the meter as it was, and reports nothing: the platform's last
     * usage is then the session's own request, already counted.
     *
     * @param resource $report
     */
    private function extract(MessageBag $bag, int $from, string $database, $report): void
    {
        $store = new MemoryStore();
        $store->open($database);
        $platform = $this->platform->withHttpClient($this->http);
        $asked = $this->usage->requests();

        $this->extractor->into($store, $platform)->extract($bag, $from);

        $usage = $platform->lastUsage();
        if ($usage !== null && $this->usage->requests() > $asked) {
            fwrite($report, json_encode($usage, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * What an ended pass spent, read from its socket, into the session's
     * meter. Read whole: its writer is gone, so the read ends at once.
     *
     * @param resource $report
     */
    private function countUsage($report): void
    {
        $sent = stream_get_contents($report);
        fclose($report);

        $usage = is_string($sent) && $sent !== '' ? json_decode($sent, true) : null;
        if (!is_array($usage) || !is_int($usage['prompt'] ?? null) || !is_int($usage['completion'] ?? null)) {
            return;
        }

        $cached = $usage['cached'] ?? 0;
        $this->usage->record(['prompt' => $usage['prompt'], 'completion' => $usage['completion'], 'cached' => is_int($cached) ? $cached : 0]);
    }

    /** Gone without a shutdown function, a destructor or a flush: none of them is this process's to run. */
    private static function vanish(): never
    {
        posix_kill(posix_getpid(), SIGKILL);

        exit(0);
    }

    /**
     * Out of the terminal: its own session, so a Ctrl+C meant for Sherpa
     * does not reach it, and /dev/null for 0, 1 and 2. False when one could
     * not be opened, and the process must not go on: closed and left closed,
     * the next file opened takes their number, so what is written to the
     * screen lands in the database; and a write that fails ends PHP through
     * the session's shutdown function, past every finally.
     */
    private function detach(): bool
    {
        posix_setsid();

        foreach ([SIGINT, SIGTERM, SIGHUP, SIGQUIT, SIGTSTP, SIGWINCH, SIGCHLD] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }

        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $this->stdio = [fopen('/dev/null', 'r'), fopen('/dev/null', 'w'), fopen('/dev/null', 'w')];

        // This copy of the turn's state, not the session's: no Ctrl+C asked
        // for, no keyboard to listen to, no spinner to draw.
        $this->interrupt->beginTurn();
        $this->interrupt->listenWith(null);
        $this->interrupt->meanwhile(null);

        return !in_array(false, $this->stdio, true);
    }
}
