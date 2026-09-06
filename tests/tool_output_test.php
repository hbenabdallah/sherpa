<?php
// What a tool sends back goes into the model's window, and it has to fit.
//
// Seen in a real session: one project_grep matched a JSON test fixture written
// on a single line, the whole line came back — about 180k tokens — and the next
// request was refused for exceeding the model's window. file_read had no bound
// at all. Both are now bounded in characters, not only in lines.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\ContextBudget;
use App\Project\ProjectPathResolver;
use App\Tool\FileReadTool;
use App\Tool\LongLine;
use App\Tool\ProjectGrepTool;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr($detail, 0, 400), "\n";
    }
}

$root = sys_get_temp_dir() . '/sherpa-tool-output-' . bin2hex(random_bytes(4));
mkdir($root . '/fixtures', 0777, true);
mkdir($root . '/src', 0777, true);

// A minified fixture: one line, half a megabyte, the word looked for in the middle.
$filler = str_repeat('{"k":"valeur","n":12345},', 10000);
file_put_contents($root . '/fixtures/devis.json', $filler . '"EMAIL":"support@example.test",' . $filler);
file_put_contents($root . '/src/Mailer.php', "<?php\n// support address comes from the configuration\nclass Mailer {}\n");

$paths = new ProjectPathResolver();
$paths->setRoot($root);

// ---- a line of a megabyte ------------------------------------------------------
$line = LongLine::clip(str_repeat('a', 5000) . 'CIBLE' . str_repeat('b', 5000), 300, 'CIBLE');
check('a long line is cut to a window', mb_strlen($line) < 400, (string) mb_strlen($line));
check('around the match, not at its start', str_contains($line, 'CIBLE') && str_starts_with($line, '…'), $line);
check('saying how long it really was', str_contains($line, '[line of 10,005 characters, cut]'), $line);
check('a short line is left alone', LongLine::clip('court', 300, 'x') === 'court');
check('a pattern PCRE cannot read still gives a bounded line', mb_strlen(LongLine::clip(str_repeat('z', 9000), 300, '(')) < 400);
check('a cut never leaves broken UTF-8', mb_check_encoding(LongLine::clip(str_repeat('é', 5000), 301), 'UTF-8'));

// ---- project_grep ---------------------------------------------------------------
$grep = new ProjectGrepTool($paths);
$found = $grep('support');
check('grep on a minified file sends back a few hundred characters, not the line',
    strlen($found) < 1500, (string) strlen($found));
check('with the match in it, and where it is', str_contains($found, 'support@example.test') && str_contains($found, 'fixtures/devis.json:1:'), $found);
check('ordinary matches come back whole', str_contains($found, 'src/Mailer.php:2:// support address comes from the configuration'), $found);

for ($i = 0; $i < 40; $i++) {
    file_put_contents($root . "/fixtures/lot{$i}.json", str_repeat('x', 3000) . 'support' . str_repeat('y', 3000));
}
$many = $grep('support');
check('many long matches stay within the search\'s share of the window',
    strlen($many) < 50 * 110 + 800, (string) strlen($many));
check('and say that some were left out', str_contains($many, 'results shown'), substr($many, -200));

// ---- file_read ---------------------------------------------------------------------
$read = new FileReadTool($paths, new ContextBudget(contextWindow: 32768));
$minified = $read('fixtures/devis.json');
check('reading a minified file sends back a bounded line', strlen($minified) < 3000, (string) strlen($minified));
check('that says it was cut', str_contains($minified, '[line of'), substr($minified, 0, 200));

$big = implode("\n", array_map(static fn(int $n) => sprintf('// ligne %05d %s', $n, str_repeat('-', 80)), range(1, 5000)));
file_put_contents($root . '/src/Big.php', $big);
$first = $read('src/Big.php');
check('a large file is cut to the read\'s share of the window', strlen($first) < 60000, (string) strlen($first));
preg_match('/lines 1-(\d+) of 5000 shown; read on with offset=(\d+)/', $first, $where);
check('saying which lines were shown, and how to read on', isset($where[2]) && (int) $where[2] === (int) $where[1] + 1, substr($first, -200));
$next = $read('src/Big.php', offset: (int) ($where[2] ?? 1));
check('reading on starts where the first read stopped', str_starts_with(ltrim($next), ($where[2] ?? '?') . ' | // ligne ' . sprintf('%05d', (int) ($where[2] ?? 0))), substr($next, 0, 80));

$wide = new FileReadTool($paths, new ContextBudget(contextWindow: 131072));
preg_match('/lines 1-(\d+) of/', $wide('src/Big.php'), $wideWhere);
check('a larger window reads more of it at once', (int) ($wideWhere[1] ?? 0) > (int) ($where[1] ?? PHP_INT_MAX), ($wideWhere[1] ?? '?') . ' vs ' . ($where[1] ?? '?'));

$small = $read('src/Mailer.php');
check('a small file comes back whole, with no note', substr_count($small, "\n") === 2 && !str_contains($small, 'truncated'), $small);

exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
