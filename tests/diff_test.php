<?php

declare(strict_types=1);

namespace App\Tests\DiffTest;

// The diff the confirmation shows for file_patch and file_write: the lines
// that change and where, as git shows them — the screen an edit is approved
// from has to say what the edit does.

require __DIR__ . '/../vendor/autoload.php';

use App\Project\ProjectPathResolver;
use App\Tool\Edit\LineDiff;
use App\Tool\FilePatchTool;
use App\Tool\FileWriteTool;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr($detail, 0, 900), "\n";
    }
}

/** @return list<string> the lines that change, without the headers */
function changes(string $diff): array
{
    return array_values(array_filter(
        explode("\n", $diff),
        fn(string $l): bool => preg_match('/^[-+] /', $l) === 1,
    ));
}

$invoice = <<<'PHP'
<?php

final class Invoice
{
    /** Before VAT, in cents. */
    public function subtotal(): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            $total += $line->unitPrice;
        }

        return $total;
    }

    public function vat(): int
    {
        return (int) round($this->subtotal() * $this->vatRate);
    }
}

PHP;

$project = sys_get_temp_dir() . '/sherpa-diff-' . getmypid();
mkdir("{$project}/src", 0777, true);
file_put_contents("{$project}/src/Invoice.php", $invoice);
$paths = new ProjectPathResolver();
$paths->setRoot($project);
$patch = new FilePatchTool($paths);
$write = new FileWriteTool($paths);

// ---- The demo: a whole method sent, one line changed --------------------------
$search = <<<'PHP'
    /** Before VAT, in cents. */
    public function subtotal(): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            $total += $line->unitPrice;
        }

        return $total;
    }
PHP;
$diff = $patch->getDiff('src/Invoice.php', $search, str_replace('$line->unitPrice;', '$line->unitPrice * $line->quantity;', $search));
check('a patch shows only the line it changes', changes($diff) === [
    '-             $total += $line->unitPrice;',
    '+             $total += $line->unitPrice * $line->quantity;',
], $diff);
check('with three lines of context on each side', str_contains($diff, "  {\n          \$total = 0;\n          foreach") && str_contains($diff, "          }\n  \n          return \$total;"), $diff);
check('and where it is, as git says it', str_contains($diff, '@@ -7,7 +7,7 @@'), $diff);
check('the rest of the file is not shown', !str_contains($diff, 'vatRate') && !str_contains($diff, '<?php'), $diff);

// ---- The path as the project knows it -------------------------------------------
$diff = $patch->getDiff("{$project}/src/Invoice.php", '$line->unitPrice;', '$line->unitPrice * $line->quantity;');
check('an absolute path is shown relative to the project', str_contains($diff, '--- a/src/Invoice.php') && !str_contains($diff, 'a//'), $diff);

$diff = $write->getDiff("{$project}/src/Invoice.php", str_replace('final class', 'class', $invoice));
check('file_write too', str_contains($diff, '+++ b/src/Invoice.php') && !str_contains($diff, $project), $diff);

// ---- file_write: an insertion is an insertion -----------------------------------
$added = str_replace("    public function vat(): int\n", "    public function lines(): int\n    {\n        return count(\$this->lines);\n    }\n\n    public function vat(): int\n", $invoice);
$diff = $write->getDiff('src/Invoice.php', $added);
check('a method inserted in the middle shows as added lines only', changes($diff) === [
    '+     public function lines(): int',
    '+     {',
    '+         return count($this->lines);',
    '+     }',
    '+ ',
], $diff);
check('nothing after it shows as removed', !str_contains($diff, "\n- "), $diff);

// ---- Changes far apart: one hunk each ------------------------------------------
$both = str_replace(['final class', 'round('], ['class', 'floor('], $invoice);
$diff = $write->getDiff('src/Invoice.php', $both);
check('two distant changes make two hunks', substr_count($diff, "\n@@ ") === 2, $diff);
check('each hunk with its own place', str_contains($diff, '@@ -1,6 +1,6 @@') && str_contains($diff, '@@ -15,6 +15,6 @@'), $diff);

$near = str_replace(['$total = 0;', 'return $total;'], ['$total = 1;', 'return $total + 0;'], $invoice);
check('changes close together share one hunk', substr_count(LineDiff::unified($invoice, $near, 'f'), "\n@@ ") === 1);

// ---- Edges --------------------------------------------------------------------------
check('no change says so', str_ends_with(LineDiff::unified($invoice, $invoice, 'f'), '(no change)'));
check('the shared final newline is not a line', !str_contains(LineDiff::unified("a\nb\n", "a\nc\n", 'f'), "\n  \n"));
check('a line added at the end', changes(LineDiff::unified("a\nb\n", "a\nb\nc\n", 'f')) === ['+ c']);
check('a file emptied', changes(LineDiff::unified("a\nb", '', 'f')) === ['- a', '- b', '+ '], LineDiff::unified("a\nb", '', 'f'));
check('an empty range sits before its line', str_contains(LineDiff::unified('', "a\n", 'f'), '@@ -0,0 +1 @@'), LineDiff::unified('', "a\n", 'f'));

$old = implode("\n", array_map(fn($i): string => "old {$i}", range(1, 600)));
$new = implode("\n", array_map(fn($i): string => "new {$i}", range(1, 600)));
$started = microtime(true);
$diff = LineDiff::unified($old, $new, 'f');
check('a rewrite too large to align shows as replaced, quickly', count(changes($diff)) === 1200 && microtime(true) - $started < 1.0);

array_map(unlink(...), glob("{$project}/src/*"));
rmdir("{$project}/src");
rmdir($project);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
