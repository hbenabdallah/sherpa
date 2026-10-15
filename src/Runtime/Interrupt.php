<?php

namespace App\Runtime;

/**
 * Ctrl+C, scoped to the turn rather than to the process: inference is unbounded
 * here, and exiting on SIGINT made "this is taking too long" and "throw the
 * conversation away" the same keystroke. During a turn it cancels the turn; at
 * an idle prompt it still quits.
 *
 * Cancellation is cooperative — callers check requested() — so a second Ctrl+C
 * quits outright rather than trapping someone behind a checkpoint that never
 * comes. The handler stays installed all session because Sherpa runs as PID 1
 * in its container, where SIG_DFL does nothing at all.
 */
class Interrupt
{
    private bool $inTurn = false;
    private bool $requested = false;
    private int $requestsThisTurn = 0;

    private const LISTEN_SECONDS = 0.02;

    /** The longest a wait holds on in a Fiber: every other one waits as long. */
    private const FIBER_POLL_SECONDS = 0.05;

    /** @var (\Closure(): void)|null */
    private ?\Closure $quit = null;

    /** @var (\Closure(): void)|null */
    private ?\Closure $listen = null;
    private float $listenedAt = 0.0;

    /** @var (\Closure(): void)|null */
    private ?\Closure $meanwhile = null;

    public function install(callable $quit): void
    {
        $this->quit = $quit(...);

        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGINT, $this->handle(...));
        pcntl_async_signals(true);
    }

    public function beginTurn(): void
    {
        $this->inTurn = true;
        $this->requested = false;
        $this->requestsThisTurn = 0;
    }

    public function endTurn(): void
    {
        $this->inTurn = false;
    }

    /**
     * A way to hear a cancel that does not come as a signal: an editor sends
     * it as a message, and nothing reads that message unless asked. Called
     * from requested(), which every wait already calls.
     *
     * @param (\Closure(): void)|null $listen
     */
    public function listenWith(?\Closure $listen): void
    {
        $this->listen = $listen;
    }

    /**
     * What to do at each check while something long runs: a command waits
     * here every 20 ms, and the screen has nothing else to say it is alive.
     * Returns the one it replaces, to be put back.
     *
     * @param (\Closure(): void)|null $tick
     *
     * @return (\Closure(): void)|null
     */
    public function meanwhile(?\Closure $tick): ?\Closure
    {
        $replaced = $this->meanwhile;
        $this->meanwhile = $tick;

        return $replaced;
    }

    /**
     * Also where a sub-agent running beside others lets them go on: every
     * wait of a request already comes through here, a chunk or half a second
     * of silence at a time, so this is where its Fiber gives way.
     */
    public function requested(): bool
    {
        if (\Fiber::getCurrent() !== null) {
            \Fiber::suspend();
        }

        if (!$this->requested && $this->meanwhile !== null) {
            ($this->meanwhile)();
        }

        // Every chunk of a reply asks: at most fifty times a second is heard
        // soon enough, and costs nothing.
        if (!$this->requested && $this->listen !== null && microtime(true) - $this->listenedAt >= self::LISTEN_SECONDS) {
            $this->listenedAt = microtime(true);
            ($this->listen)();
        }

        return $this->requested;
    }

    /**
     * How long one wait may block before coming back through requested():
     * $seconds alone, much less beside other sub-agents, whose requests
     * would not even leave until it gives way.
     */
    public static function poll(float $seconds): float
    {
        return \Fiber::getCurrent() === null ? $seconds : min($seconds, self::FIBER_POLL_SECONDS);
    }

    /** Raise cancellation without a signal — used by callers and by tests. */
    public function request(): void
    {
        $this->requested = true;
    }

    private function handle(): void
    {
        if (!$this->inTurn) {
            $this->quitNow();

            return;
        }

        $this->requested = true;
        $this->requestsThisTurn++;

        if ($this->requestsThisTurn >= 2) {
            $this->quitNow();
        }
    }

    private function quitNow(): void
    {
        if ($this->quit !== null) {
            ($this->quit)();
        }
    }
}
