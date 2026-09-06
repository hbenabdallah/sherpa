<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * The plan the model keeps for a task of several steps: what is done, what is
 * under way, what is left. Written whole by the todo_write tool each time —
 * a full list is harder to get wrong than an edit to one — shown on screen as
 * it changes, and kept for the session.
 *
 * Plain text in and out, one task per line with a Markdown checkbox, since
 * models write that without a schema to follow and some providers refuse a
 * tool parameter that is an array of objects.
 */
final class TodoList
{
    public const TODO = 'todo';
    public const DOING = 'doing';
    public const DONE = 'done';

    /** @var list<array{status: string, text: string}> */
    private array $tasks = [];

    /**
     * Replace the list with the one written. Lines that are not tasks are
     * ignored, so a heading or a blank line does no harm.
     *
     * @return int how many tasks it now holds
     */
    public function replace(string $written): int
    {
        $tasks = [];

        foreach (preg_split('/\R/u', $written) ?: [] as $line) {
            // "[ ] a", "- [x] b", "1. [>] c", "* [-] d"
            if (preg_match('/^\s*(?:[-*+]|\d+[.)])?\s*\[([ xX>~\-\/])\]\s*(.+?)\s*$/u', $line, $m) !== 1) {
                continue;
            }

            $tasks[] = [
                'status' => match ($m[1]) {
                    'x', 'X'           => self::DONE,
                    '>', '~', '-', '/' => self::DOING,
                    default            => self::TODO,
                },
                'text' => $m[2],
            ];
        }

        $this->tasks = $tasks;

        return count($tasks);
    }

    public function clear(): void
    {
        $this->tasks = [];
    }

    public function isEmpty(): bool
    {
        return $this->tasks === [];
    }

    /** @return list<array{status: string, text: string}> */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /** The list as the model reads it back: the same checkboxes it writes. */
    public function asText(): string
    {
        return implode("\n", array_map(
            static fn(array $t) => match ($t['status']) {
                self::DONE  => '[x] ',
                self::DOING => '[>] ',
                default     => '[ ] ',
            } . $t['text'],
            $this->tasks,
        ));
    }

    /** "2/5 done", for a line that says where the plan stands. */
    public function progress(): string
    {
        $done = count(array_filter($this->tasks, static fn(array $t) => $t['status'] === self::DONE));

        return "{$done}/" . count($this->tasks) . ' done';
    }
}
