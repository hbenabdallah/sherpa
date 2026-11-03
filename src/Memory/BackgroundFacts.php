<?php

declare(strict_types=1);

namespace App\Memory;

use App\Agent\MessageBag;
use App\Platform\SwitchablePlatform;
use App\Runtime\Interrupt;
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

    /** The pass still running, so the next one waits its turn rather than doubling the requests. */
    private ?int $child = null;

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
    ) {}

    /**
     * Whether a pass started now would see its end. Not in a container: the
     * container stops when Sherpa does, and every process in it goes too.
     */
    public function survivesExit(): bool
    {
        return !file_exists('/.dockerenv') && !file_exists('/run/.containerenv');
    }

    /** Until the pass under way ends, or Ctrl+C. */
    public function wait(): void
    {
        while ($this->isRunning() && !$this->interrupt->requested()) {
            usleep(self::WAIT_STEP_US);
        }
    }

    public function isRunning(): bool
    {
        if ($this->child === null) {
            return false;
        }

        if (pcntl_waitpid($this->child, $status, WNOHANG) === 0) {
            return true;
        }

        $this->child = null;

        return false;
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

        $pid = pcntl_fork();
        if ($pid === -1) {
            return false;
        }

        if ($pid > 0) {
            $this->child = $pid;

            return true;
        }

        if (!$this->detach()) {
            self::vanish();
        }

        // Whatever happens, this process ends here and only here: an
        // exception left to unwind would run the session's shutdown function,
        // which hands the tty back and stops the MCP servers and jobs, under
        // the session still using them. Nothing is left to tell either: the
        // pass reports to a terminal this process no longer has.
        try {
            $store = new MemoryStore();
            $store->open($database);
            $this->extractor->into($store, $this->platform->withHttpClient($this->http))->extract($bag, $from);
        } finally {
            self::vanish();
        }
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
