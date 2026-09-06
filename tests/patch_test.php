<?php
// file_patch and file_write: placing an edit the way the model meant it, and
// never breaking a file that parsed. Every case below is a failure the
// benchmark actually recorded — file_patch was failing one call in two.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\Tool\ToolCall;
use App\Agent\Tool\Toolbox;
use App\Project\ProjectPathResolver;
use App\Tool\Edit\SyntaxGuard;
use App\Tool\Edit\TextPatch;
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
        echo '        ', substr($detail, 0, 500), "\n";
    }
}

$controller = <<<'PHP'
<?php

namespace Boutique\Controller;

use Boutique\Routing\Route;
use Boutique\User\UserRepo;

final class CartController
{
    public function __construct(private readonly UserRepo $users = new UserRepo()) {}
}
PHP;

// ---- exact matches behave as they always did -------------------------------
$exact = TextPatch::apply($controller, 'final class CartController', 'final class BasketController');
check('an exact, unique match is replaced', $exact->ok() && str_contains((string) $exact->content, 'final class BasketController'));
check('and reported as exact, at its line', $exact->how === 'exact' && $exact->line === 8, "{$exact->how} {$exact->line}");

$twice = TextPatch::apply($controller, 'UserRepo', 'UserRepository');
check('an ambiguous match is refused', !$twice->ok());
check('naming the lines it was found on, so it can be narrowed',
    str_contains((string) $twice->error, '3 times') && str_contains((string) $twice->error, 'lines 6, 10'), (string) $twice->error);

check('an empty search is an error, not a PHP ValueError', !TextPatch::apply($controller, '', 'x')->ok());

// ---- 7 of the 17 recorded failures: backslashes escaped twice --------------
$doubled = TextPatch::apply(
    $controller,
    'use Boutique\\\\User\\\\UserRepo;',
    'use Boutique\\\\User\\\\UserRepository;',
);
check('a search with doubled backslashes finds the namespace', $doubled->ok(), (string) $doubled->error);
check('and the replacement is collapsed the same way, so the file stays valid PHP',
    str_contains((string) $doubled->content, "use Boutique\\User\\UserRepository;\n")
    && !str_contains((string) $doubled->content, '\\\\'), (string) $doubled->content);
check('saying how it was matched', $doubled->how === 'doubled backslashes');

$legit = "\$pattern = '\\\\d+';\n\$other = '\\d+';\n";
check('real double backslashes still match exactly first',
    TextPatch::apply($legit, "'\\\\d+'", "'\\\\w+'")->how === 'exact');

// ---- 8 of 17: a backslash turned into a dot. Not guessable — but quotable --
$dotted = TextPatch::apply($controller, 'use Boutique\\User.UserRepo;', 'use Boutique\\User\\UserRepository;');
check('a mangled search is not "corrected" into a guess', !$dotted->ok());
check('the error quotes the closest line of the file, exactly',
    str_contains($dotted->message(), "line 6") && str_contains($dotted->message(), 'use Boutique\\User\\UserRepo;'),
    $dotted->message());

$nothing = TextPatch::apply($controller, 'function somethingElseEntirely(array $x)', 'x');
check('with nothing close, it says to read the file again', str_contains($nothing->message(), 'read the file again'), $nothing->message());

// ---- 1 of 17: the right lines at the wrong indentation ---------------------
$tests = <<<'PHP'
<?php

function test_truncate_cuts_long_text(): void
{
    assert_same('Bonj…', Str::truncate('Bonjour', 4));
}
PHP;
$reindented = TextPatch::apply(
    $tests,
    "function test_truncate_cuts_long_text(): void\n    {\n        assert_same('Bonj…', Str::truncate('Bonjour', 4));\n    }",
    "function test_truncate_cuts_long_text(): void\n    {\n        assert_same('Bonj…', Str::truncate('Bonjour', 4));\n    }\n\nfunction test_slugify(): void\n{\n    assert_same('a-b', Str::slugify('A b'));\n}",
);
check('lines at the wrong indentation are still found', $reindented->ok(), (string) $reindented->error);
check('the lines it repeats keep the file\'s own indentation',
    str_contains((string) $reindented->content, "void\n{\n    assert_same('Bonj…'"), (string) $reindented->content);
check('and the new ones are added', str_contains((string) $reindented->content, 'function test_slugify(): void'));

$crlf = TextPatch::apply("a\r\nb\r\nc\r\n", "a\nb", "a\nB");
check('a CRLF file is patched from a plain-newline search, keeping its endings',
    $crlf->ok() && $crlf->content === "a\r\nB\r\nc\r\n", json_encode($crlf->content));

// ---- the syntax guard ------------------------------------------------------
$guard = new SyntaxGuard();
check('valid PHP passes', $guard->problem('a.php', "<?php\nuse A\\B;\n") === null);
$broken = $guard->problem('src/Cart.php', "<?php\nuse Boutique.User.UserRepository;\n");
check('the benchmark\'s broken rename does not', $broken !== null, (string) $broken);
check('and the message names the file and the line, not a temp file',
    str_contains((string) $broken, 'src/Cart.php') && str_contains((string) $broken, 'line 2') && !str_contains((string) $broken, 'sherpa-lint'),
    (string) $broken);
check('JSON is checked too', $guard->problem('composer.json', '{"a": 1,}') !== null && $guard->problem('x.json', '{"a": 1}') === null);
check('other files are not judged', $guard->problem('notes.md', '<?php (((') === null);

// Inside the single executable PHP_BINARY is Sherpa itself, so `php -l` is not
// available: PHP's parser runs in the process instead, with the same verdicts.
$inside = new SyntaxGuard(inProcess: true);
check('in-process, valid PHP passes too', $inside->problem('a.php', "<?php\nuse A\\B;\n") === null);
$broken = $inside->problem('src/Cart.php', "<?php\nuse Boutique.User.UserRepository;\n");
check('and the broken rename is caught, with its file and line',
    str_contains((string) $broken, 'src/Cart.php') && str_contains((string) $broken, 'line 2'), (string) $broken);

// ---- the tools, end to end -------------------------------------------------
$dir = sys_get_temp_dir() . '/sherpa-patch-' . bin2hex(random_bytes(4));
mkdir($dir . '/src', 0777, true);
file_put_contents("{$dir}/src/CartController.php", $controller);
$paths = new ProjectPathResolver();
$paths->setRoot($dir);
$toolbox = new Toolbox([new FilePatchTool($paths), new FileWriteTool($paths)]);
$patch = fn(string $search, string $replace, string $path = 'src/CartController.php') =>
    $toolbox->execute(new ToolCall('c', 'file_patch', ['path' => $path, 'search' => $search, 'replace' => $replace]));

$result = $patch('use Boutique\\\\User\\\\UserRepo;', 'use Boutique\\\\User\\\\UserRepository;');
check('file_patch applies the collapsed form', !$result->isError && str_contains(file_get_contents("{$dir}/src/CartController.php"), "use Boutique\\User\\UserRepository;"), $result->content);
check('and tells the model to write backslashes once', str_contains($result->content, 'write them once'), $result->content);

$before = file_get_contents("{$dir}/src/CartController.php");
$result = $patch('use Boutique\\User\\UserRepository;', 'use Boutique.User.UserRepository;');
check('a replacement that breaks the PHP is refused', $result->isError, $result->content);
check('with the parser\'s reason', str_contains($result->content, 'would break') && str_contains($result->content, 'line 6'), $result->content);
check('and the file is left exactly as it was', file_get_contents("{$dir}/src/CartController.php") === $before);

$result = $patch('use Boutique\\User.UserRepo;', 'x');
check('a failed search is flagged as an error, with the closest line', $result->isError && str_contains($result->content, 'use Boutique\\User\\UserRepository;'), $result->content);

// A file that was already broken can be repaired a step at a time.
file_put_contents("{$dir}/src/Broken.php", "<?php\nfunction a( {\nfunction b( {\n");
$result = $patch('function a( {', 'function a() {', 'src/Broken.php');
check('a file that did not parse can still be patched', !$result->isError, $result->content);

$write = fn(string $path, string $content) => $toolbox->execute(new ToolCall('w', 'file_write', ['path' => $path, 'content' => $content]));
$result = $write('src/New.php', "<?php\nclass New_ {\n");
check('file_write refuses a new file that does not parse', $result->isError && !is_file("{$dir}/src/New.php"), $result->content);
$result = $write('src/New.php', "<?php\nclass New_ {}\n");
check('and writes one that does', !$result->isError && is_file("{$dir}/src/New.php"), $result->content);

exec('rm -rf ' . escapeshellarg($dir));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
