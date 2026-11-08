<?php

declare(strict_types=1);

namespace App\TUI;

use App\Permission\CommandPolicy;

final class StandingGrants
{
    /**
     * A compact summary of the project grants, without putting commands on
     * the startup line.
     *
     * @param array<int, string> $grants
     */
    public static function summary(array $grants): string
    {
        $tools = [];
        $mcpTools = [];
        $commands = 0;

        foreach ($grants as $grant) {
            if (str_starts_with($grant, CommandPolicy::PREFIX)) {
                $commands++;
                continue;
            }

            if (str_starts_with($grant, 'mcp__')) {
                $parts = explode('__', substr($grant, strlen('mcp__')), 2);
                if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                    $mcpTools[$parts[0]][] = $grant;
                    continue;
                }
            }

            $tools[] = Terminal::plain($grant, singleLine: true);
        }

        $entries = $tools;
        foreach ($mcpTools as $server => $serverTools) {
            $count = count($serverTools);
            if ($count > 3) {
                $entries[] = Terminal::plain("{$count} MCP tools ({$server})", singleLine: true);
                continue;
            }

            foreach ($serverTools as $tool) {
                $entries[] = Terminal::plain($tool, singleLine: true);
            }
        }

        if ($commands > 0) {
            $commandCount = $commands . ' command' . ($commands === 1 ? '' : 's');
            $entries[] = $entries === [] ? $commandCount : 'and ' . $commandCount;
        }

        return Terminal::plain(implode(', ', $entries), singleLine: true);
    }
}
