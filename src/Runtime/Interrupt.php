<?php

namespace App\Runtime;

/**
 * Ctrl+C, scoped to the turn rather than to the process.
 *
 * Inference here is deliberately unbounded, and a turn lasts anywhere from a
 * couple of seconds to several minutes depending on the model and the machine.
 * Exiting on SIGINT made "this is taking too long" and "throw away the
 * conversation" the same keystroke. So during a turn the signal cancels the
 * turn; at an idle prompt it still quits, which is what Ctrl+C means there.
 *
 * The distinction earns its keep on a slow machine and costs nothing on a fast
 * one, where the turn is usually over before anyone reaches for the key.
 *
 * Cancellation is cooperative — callers check requested() at their own
 * checkpoints — so a second Ctrl+C in the same turn quits outright rather than
 * trapping someone behind a checkpoint that never comes.
 *
 * The handler stays installed for the whole session, and it has to: Sherpa runs
 * as PID 1 in its container, where the kernel applies no default action to a
 * signal with no handler. SIG_DFL there does not quit — it does nothing at all.
 * Reaching the handler at an idle prompt is LineEditor's job; it waits in
 * stream_select() rather than inside readline() so the signal can be dispatched.
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
