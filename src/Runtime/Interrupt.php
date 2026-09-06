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

    /** @var (\Closure(): void)|null */
    private ?\Closure $quit = null;

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

    public function requested(): bool
    {
        return $this->requested;
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
