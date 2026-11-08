<?php

declare(strict_types=1);

namespace App\Tests\StandingGrantsTest;

require __DIR__ . '/../vendor/autoload.php';

use App\Permission\CommandPolicy;
use App\TUI\StandingGrants;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', $detail, "\n";
    }
}

$summary = StandingGrants::summary([
    'shell_exec: sed -n 1,2p vendor/Protocol.php',
    'file_write',
    'mcp__printoclock_jobs__one',
    'shell_exec: php -r \'echo 1;\necho 2;\'',
    'file_patch',
    'mcp__printoclock_jobs__two',
    'mcp__printoclock_jobs__three',
    'web_fetch',
    'mcp__printoclock_jobs__four',
    'mcp__clock__list',
]);
check(
    'whole tools appear before grouped MCP tools and command counts',
    $summary === 'file_write, file_patch, web_fetch, 4 MCP tools (printoclock_jobs), mcp__clock__list, and 2 commands',
    $summary,
);
check('command grant contents do not appear in the startup summary', !str_contains($summary, 'sed') && !str_contains($summary, 'php -r'));
check('a multi-line grant cannot break the startup summary', !str_contains($summary, "\n") && !str_contains($summary, 'echo 2'));
check(
    'three or fewer MCP tools stay named in the summary',
    StandingGrants::summary(['mcp__jobs__one', 'mcp__jobs__two', 'mcp__jobs__three'])
        === 'mcp__jobs__one, mcp__jobs__two, mcp__jobs__three',
);
check('a single exact command uses the singular count', StandingGrants::summary([CommandPolicy::PREFIX . 'ls -la']) === '1 command');
check('text in a shown grant is flattened', StandingGrants::summary(["file_write\nsecond line"]) === 'file_write second line');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
