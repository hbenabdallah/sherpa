<?php
// list_dir: the one tool that shows how a project is laid out. Before it, the
// model's only ways in were guessing grep patterns and guessing file names —
// and a live run showed it calling file_read on the project root.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\Tool\ToolCall;
use App\Agent\Tool\Toolbox;
use App\Project\ProjectPathResolver;
use App\Tool\ListDirTool;

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

$root = sys_get_temp_dir() . '/sherpa-tree-' . bin2hex(random_bytes(4));
foreach (['src/Billing', 'src/Catalog/Deep/Deeper', 'tests', 'vendor/acme/lib', '.git/objects', 'crowded'] as $dir) {
    mkdir("{$root}/{$dir}", 0777, true);
}
foreach (['src/Billing/Invoice.php', 'src/Billing/Tax.php', 'src/Catalog/Product.php', 'src/Catalog/Deep/Deeper/Leaf.php',
          'tests/run.php', 'vendor/acme/lib/Lib.php', 'composer.json', 'README.md', '.env.example'] as $file) {
    file_put_contents("{$root}/{$file}", "x\n");
}
for ($i = 1; $i <= 55; $i++) {
    touch(sprintf('%s/crowded/f%02d.txt', $root, $i));
}

$paths = new ProjectPathResolver();
$paths->setRoot($root);
$tool = new ListDirTool($paths);
$toolbox = new Toolbox([$tool]);
$list = fn(array $args = []) => $toolbox->execute(new ToolCall('c', 'list_dir', $args));

$top = $list();
check('the root lists without arguments', !$top->isError, $top->content);
check('directories come first, marked with a slash',
    strpos($top->content, 'src/') < strpos($top->content, 'composer.json'), $top->content);
check('two levels are opened by default', str_contains($top->content, "src/\n  Billing/"), $top->content);
check('and the level below is counted rather than listed',
    str_contains($top->content, 'Billing/ (2 entries)') && !str_contains($top->content, 'Invoice.php'), $top->content);
check('dotfiles that matter are shown', str_contains($top->content, '.env.example'));
check('vendor is named but not opened',
    str_contains($top->content, 'vendor/ (not expanded)') && !str_contains($top->content, 'acme'), $top->content);
check('and neither is .git', str_contains($top->content, '.git/ (not expanded)') && !str_contains($top->content, 'objects'));

$sub = $list(['path' => 'src', 'depth' => 4]);
check('a subdirectory can be listed deeper', str_contains($sub->content, 'Leaf.php'), $sub->content);
check('with paths relative to it, indented by level', str_contains($sub->content, "Catalog/\n  Deep/\n    Deeper/\n      Leaf.php"), $sub->content);

$clamped = $list(['path' => 'src', 'depth' => 99]);
check('depth is clamped rather than refused', !$clamped->isError);

$crowded = $list(['path' => 'crowded']);
check('a crowded directory names the first entries', str_contains($crowded->content, 'f01.txt'));
check('and counts the rest', str_contains($crowded->content, '… and 15 more'), $crowded->content);

$file = $list(['path' => 'composer.json']);
check('a file is an error that points to file_read', $file->isError && str_contains($file->content, 'file_read'), $file->content);

$missing = $list(['path' => 'nowhere']);
check('a missing directory is an error', $missing->isError, $missing->content);

// Seen in the benchmark: the tree shows `src/` then `  Routing/`, and the model
// asks for `Routing`. The error now says where it is.
$lost = $list(['path' => 'Billing']);
check('a directory asked for by its bare name points to where it is',
    $lost->isError && str_contains($lost->content, 'did you mean src/Billing?'), $lost->content);
check('and nothing is suggested when nothing is close', !str_contains($missing->content, 'did you mean'), $missing->content);

$outside = $list(['path' => '../']);
check('a directory outside the project is refused', $outside->isError, $outside->content);

$etc = $list(['path' => '/etc']);
check('and so is an absolute path elsewhere', $etc->isError, $etc->content);

// Bounded like every other tool's output: a tree is cheap to ask for and can be
// enormous.
$big = sys_get_temp_dir() . '/sherpa-tree-big-' . bin2hex(random_bytes(4));
for ($d = 1; $d <= 8; $d++) {
    mkdir("{$big}/d{$d}", 0777, true);
    for ($f = 1; $f <= 30; $f++) {
        touch("{$big}/d{$d}/f{$f}.php");
    }
}
$bigPaths = new ProjectPathResolver();
$bigPaths->setRoot($big);
$huge = (new ListDirTool($bigPaths))();
check('a large tree is truncated, and says how to see more',
    str_contains($huge, 'truncated at 150 lines') && substr_count($huge, "\n") <= 151, (string) substr_count($huge, "\n"));

exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($big));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
