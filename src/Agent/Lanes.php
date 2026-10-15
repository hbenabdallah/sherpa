<?php

namespace App\Agent;

/**
 * Which of the sub-agents running together the current code works for,
 * numbered from 1 in the order they were asked for: their lines on screen
 * interleave, and "↳ project_grep" three times over could be anyone's.
 * Kept by Fiber, since each runs in its own; outside one, there is no number.
 */
final class Lanes
{
    /** @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, int>|null */
    private static ?\WeakMap $numbers = null;

    /** @param \Fiber<mixed, mixed, mixed, mixed> $fiber */
    public static function assign(\Fiber $fiber, int $number): void
    {
        self::$numbers ??= new \WeakMap();
        self::$numbers[$fiber] = $number;
    }

    /** "[2] ", or nothing outside sub-agents running together. */
    public static function prefix(): string
    {
        $fiber = \Fiber::getCurrent();
        $number = $fiber === null ? null : self::$numbers[$fiber] ?? null;

        return $number === null ? '' : "[{$number}] ";
    }
}
