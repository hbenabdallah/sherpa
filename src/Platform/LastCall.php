<?php

namespace App\Platform;

use Fiber;
use WeakMap;

/**
 * What a platform keeps of its last request — the tokens it took, how fast it
 * went — kept apart for each sub-agent that runs at the same time as others.
 * Each runs in a Fiber, and one that finished between another's last chunk
 * and its reading of it gave that one its own numbers, or none.
 */
trait LastCall
{
    /** @var array<string, mixed> outside any Fiber: the main conversation */
    private array $mainCall = [];

    /** @var WeakMap<Fiber<mixed, mixed, mixed, mixed>, array<string, mixed>>|null */
    private ?WeakMap $fiberCalls = null;

    private function lastOf(string $key): mixed
    {
        $fiber = Fiber::getCurrent();

        return $fiber instanceof Fiber ? $this->fiberCalls[$fiber][$key] ?? null : $this->mainCall[$key] ?? null;
    }

    private function keepLast(string $key, mixed $value): void
    {
        $fiber = Fiber::getCurrent();
        if (!$fiber instanceof Fiber) {
            $this->mainCall[$key] = $value;

            return;
        }

        $this->fiberCalls ??= new WeakMap();
        $calls = $this->fiberCalls[$fiber] ?? [];
        $calls[$key] = $value;
        $this->fiberCalls[$fiber] = $calls;
    }
}
