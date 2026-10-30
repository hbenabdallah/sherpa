<?php

declare(strict_types=1);

namespace App\Acp;

use App\TUI\Terminal;

/**
 * How a tool call reads in an editor: a title a person understands at a
 * glance, and one of ACP's kinds, which picks its icon and how it is drawn.
 */
final class ToolDescriptions
{
    private const int TITLE_CHARS = 80;

    public static function kind(string $tool): string
    {
        return match ($tool) {
            'file_read', 'list_dir', 'context_recall', 'job_output', 'skill_load' => 'read',
            'project_grep', 'file_find', 'doc_search', 'memory_recall'           => 'search',
            'file_write', 'file_patch'                                            => 'edit',
            'shell_exec'                                                          => 'execute',
            'web_fetch'                                                           => 'fetch',
            'delegate', 'plan_ready', 'todo_write'                                => 'think',
            default                                                               => 'other',
        };
    }

    /** @param array<string, mixed> $args */
    public static function title(string $tool, array $args): string
    {
        $arg = static fn(string $key): string => is_scalar($args[$key] ?? null) ? trim((string) $args[$key]) : '';

        $title = match ($tool) {
            'shell_exec'      => $arg('command') !== '' ? $arg('command') : 'Run a command',
            'file_read'       => 'Read ' . ($arg('path') ?: 'a file') . ($arg('offset') !== '' ? " from line {$arg('offset')}" : ''),
            'file_write'      => 'Write ' . ($arg('path') ?: 'a file'),
            'file_patch'      => 'Edit ' . ($arg('path') ?: 'a file'),
            'list_dir'        => 'List ' . ($arg('path') ?: 'the project'),
            'project_grep'    => 'Search for ' . $arg('pattern') . ($arg('path') !== '' ? " in {$arg('path')}" : ''),
            'file_find'       => 'Find ' . $arg('pattern') . ($arg('path') !== '' ? " in {$arg('path')}" : ''),
            'doc_search'      => 'Search the docs for ' . $arg('query'),
            'web_fetch'       => 'Fetch ' . $arg('url'),
            'memory_recall'   => $arg('search') !== '' ? 'Recall ' . $arg('search') : 'Recall what is known about the project',
            'memory_remember' => 'Remember ' . $arg('key'),
            'context_recall'  => 'Recall an earlier result',
            'skill_load'      => 'Load the skill ' . $arg('name'),
            'job_output'      => 'Read the output of job ' . $arg('id'),
            'job_stop'        => 'Stop job ' . $arg('id'),
            'delegate'        => 'Sub-agent: ' . $arg('task'),
            'plan_ready'      => 'Plan ready',
            'todo_write'      => 'Update the plan',
            default           => $tool,
        };

        return mb_strimwidth(Terminal::plain($title, singleLine: true), 0, self::TITLE_CHARS, '…');
    }
}
