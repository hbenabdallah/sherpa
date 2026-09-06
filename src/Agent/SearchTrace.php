<?php

declare(strict_types=1);

namespace App\Agent;

use App\Memory\MemoryStore;

/**
 * How the model went looking for code, this turn — so that "should Sherpa index
 * the code" is settled by a number rather than an opinion. A search that found
 * nothing is recorded by ProjectGrepTool; this class records the sharper fact,
 * whether what was wanted came in one search or after three guessed patterns.
 * Groping is the failure a semantic index would fix, and the one a miss count
 * cannot tell from a model with several things to look up.
 *
 * Here rather than in AgentLoop: the loop has no business knowing about project
 * memory, and this rule is worth testing on its own.
 */
final class SearchTrace
{
    /**
     * Searches before a read, past which the model was not searching but
     * guessing. Three is where "I had a couple of things to check" stops being
     * the likelier explanation.
     */
    private const GROPING_THRESHOLD = 3;

    private int $searches = 0;
    private bool $settled = false;

    public function __construct(private readonly ?MemoryStore $store = null) {}

    public function beginTurn(): void
    {
        $this->searches = 0;
        $this->settled = false;
    }

    /**
     * One tool call, as it is executed.
     *
     * Only the run up to the first read counts. What the model does afterwards
     * is acting on something it found, not looking for it.
     *
     * @param bool $failed whether the call came back as an error
     */
    public function observe(string $tool, bool $failed = false): void
    {
        if ($this->settled) {
            return;
        }

        if ($tool === 'project_grep') {
            $this->searches++;

            return;
        }

        // Reading a file is the model saying it has found where to look — but
        // only a read that worked. A path that does not exist is one more
        // guess, and settling on it would record a groping turn as direct.
        if ($tool === 'file_read' && !$failed) {
            $this->settle();
        }
    }

    public function endTurn(): void
    {
        $this->settle();
    }

    /**
     * A turn that searched and never read is recorded the same way: the model
     * gave up, or answered from the search results alone. Either way the number
     * of attempts is the fact worth keeping.
     */
    private function settle(): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;

        if ($this->searches === 0) {
            return;
        }

        $this->store?->bump($this->searches >= self::GROPING_THRESHOLD ? 'grep_groping' : 'grep_direct');
    }

    /** Searches counted so far this turn, for tests and for /memory. */
    public function searchesThisTurn(): int
    {
        return $this->searches;
    }
}
