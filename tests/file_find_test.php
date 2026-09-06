<?php
// file_find: files by name pattern, confined to the project, newest first.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Project\PathOutsideProjectException;
use App\Project\ProjectPathResolver;
use App\Tool\FileFindTool;

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

// ---- globs ------------------------------------------------------------------
$matches = fn(string $glob, string $path) => preg_match(FileFindTool::toRegex($glob), $path) === 1;
check('* stays within a directory', $matches('src/*.php', 'src/A.php') && !$matches('src/*.php', 'src/Billing/A.php'));
check('** goes through them, none included', $matches('src/**/*.php', 'src/A.php') && $matches('src/**/*.php', 'src/Billing/Tax/A.php'));
check('? is one character', $matches('a?.txt', 'ab.txt') && !$matches('a?.txt', 'abc.txt'));
check('{a,b} is either', $matches('{README,CHANGELOG}.md', 'CHANGELOG.md') && !$matches('{README,CHANGELOG}.md', 'LICENSE.md'));
check('[abc] and [!abc] are classes', $matches('[AB]*.php', 'Bill.php') && $matches('[!AB]*.php', 'Cart.php') && !$matches('[!AB]*.php', 'Bill.php'));
check('a dot is a dot', !$matches('*.php', 'aXphp'));

// ---- a project on disk, no git -------------------------------------------------
$root = sys_get_temp_dir() . '/sherpa-find-' . bin2hex(random_bytes(4));
foreach (['src/Billing', 'tests', 'vendor/acme', 'node_modules/x', 'docs'] as $dir) {
    mkdir("{$root}/{$dir}", 0777, true);
}
$files = [
    'src/Billing/Invoice.php' => 100, 'src/Billing/Tax.php' => 300, 'src/Kernel.php' => 200,
    'tests/InvoiceTest.php' => 400, 'tests/TaxTest.php' => 50, 'vendor/acme/InvoiceTest.php' => 500,
    'node_modules/x/index.js' => 500, 'docs/README.md' => 10, 'config.yaml' => 10,
];
foreach ($files as $file => $age) {
    file_put_contents("{$root}/{$file}", 'x');
    touch("{$root}/{$file}", time() - $age);
}

$paths = new ProjectPathResolver();
$paths->setRoot($root);
$find = new FileFindTool($paths);

$out = $find('*Test.php');
check('a name pattern matches anywhere in the project', str_contains($out, 'tests/InvoiceTest.php') && str_contains($out, 'tests/TaxTest.php'), $out);
check('but not in vendor/ or node_modules/', !str_contains($out, 'vendor/') && !str_contains($out, 'node_modules'), $out);
check('the count comes first', str_starts_with($out, '2 files match'), $out);
check('the most recently changed first', strpos($out, 'TaxTest.php') < strpos($out, 'InvoiceTest.php'), $out);

$out = $find('src/**/*.php');
check('a path pattern is taken from the root', substr_count($out, "\n") === 3 && !str_contains($out, 'tests/'), $out);

$out = $find('*.php', 'src/Billing');
check('path narrows the search, and the paths stay relative to the root',
    str_contains($out, 'src/Billing/Invoice.php') && !str_contains($out, 'Kernel.php'), $out);

$out = $find('src/*.php');
check('a miss says why when "*" was meant to cross directories',
    str_contains($find('lib/*.php'), 'No file matches') && str_contains($find('lib/*.php'), '"**"'), $find('lib/*.php'));

$threw = false;
try { $find('*.php', '../'); } catch (PathOutsideProjectException) { $threw = true; }
check('a path outside the project is refused', $threw);

$threw = false;
try { $find('   '); } catch (RuntimeException $e) { $threw = str_contains($e->getMessage(), 'empty pattern'); }
check('an empty pattern is an error the model can act on', $threw);

// ---- in a git repository: .gitignore counts -----------------------------------
exec('git -C ' . escapeshellarg($root) . ' init -q 2>&1');
file_put_contents("{$root}/.gitignore", "docs/\n");
$out = $find('*.md');
check('what .gitignore leaves out, file_find leaves out', str_starts_with($out, 'No file matches'), $out);
check('untracked files are still found', str_contains($find('*.yaml'), 'config.yaml'), $find('*.yaml'));

// ---- a long list is capped ----------------------------------------------------
mkdir("{$root}/many");
for ($i = 0; $i < 150; $i++) {
    file_put_contents("{$root}/many/f{$i}.txt", 'x');
}
$out = $find('many/*.txt');
check('a long list is capped, with how many were left out',
    str_starts_with($out, '150 files match') && str_contains($out, '… and 50 more'), substr($out, -80));

exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
