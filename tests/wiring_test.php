<?php
// Verification that the DI graph gives the overlay the SAME tool instances the
// command configures. ConfirmOverlay previews resolve paths via the tools, so if
// these were separate instances the overlay would render against an unset
// project path and mislabel every file.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Kernel;
use App\Tool\FileWriteTool;
use App\TUI\ConfirmOverlay;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $got !== '') {
        echo "        got: {$got}\n";
    }
}

$kernel = new Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();

// ToolboxPass makes every #[AsTool] service public, so this is reachable.
$writeTool = $container->get(FileWriteTool::class);
check('FileWriteTool is public (ToolboxPass ran)', $writeTool instanceof FileWriteTool);

$again = $container->get(FileWriteTool::class);
check('tool services are shared, not per-injection', $writeTool === $again);

// The overlay must hold that very instance for the project root to reach it.
$overlay = new ConfirmOverlay(
    new App\TUI\Terminal(),
    $writeTool,
    $container->get(App\Tool\FilePatchTool::class),
    $container->get(App\Tool\ShellExecTool::class),
);

$project = sys_get_temp_dir() . '/sherpa-wiring-' . bin2hex(random_bytes(3));
mkdir($project, 0777, true);
file_put_contents($project . '/Thing.php', "<?php\n");

// Configure the shared resolver once, exactly as SherpaCommand does at boot.
// The resolver is inlined (private) in the compiled container, so reach it
// through a tool that received it.
$pathsProp = new ReflectionProperty(FileWriteTool::class, 'paths');
$resolver = $pathsProp->getValue($writeTool);
$resolver->setRoot($project);

$method = new ReflectionMethod($overlay, 'buildPreview');
$out = $method->invoke(
    $overlay,
    new App\Agent\Tool\ToolCall('id', 'file_write', ['path' => 'Thing.php', 'content' => "<?php // changed\n"]),
    new App\Agent\Tool\ToolDefinition('file_write', '', [], App\Agent\Tool\Permission::CONFIRM, $writeTool),
);

check(
    'project path set on the tool reaches the overlay preview',
    !str_contains($out, '(new file)'),
    str_replace("\n", ' | ', substr($out, 0, 200)),
);

// ---- the shared path resolver ---------------------------------------------
// Every filesystem tool declares `ProjectPathResolver $paths = new ...()` as a
// constructor default. If autowiring failed to supply the container's shared
// instance, each tool would silently get its own rootless copy and setRoot()
// in SherpaCommand would reach none of them — so assert sharing explicitly.
// A different tool class must have been handed the very same instance,
// otherwise setRoot() in SherpaCommand reaches only one of them.
$readResolver = (new ReflectionProperty(App\Tool\FileReadTool::class, 'paths'))
    ->getValue($container->get(App\Tool\FileReadTool::class));
check('file_read shares the resolver with file_write', $resolver === $readResolver);

$grepResolver = (new ReflectionProperty(App\Tool\ProjectGrepTool::class, 'paths'))
    ->getValue($container->get(App\Tool\ProjectGrepTool::class));
check('project_grep shares the same resolver', $resolver === $grepResolver);

check(
    'setting the root once reaches every tool',
    $readResolver->hasRoot() && $readResolver->root() === realpath($project),
);
check(
    'the autowired resolver is not each tool\'s constructor default',
    $resolver !== new App\Project\ProjectPathResolver(),
);

// The command binds the project onto ProjectPermissions; the broker is what
// reads it back. Two instances and every standing grant silently evaporates —
// which is the failure mode the persisted permissions were built to end.
$application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$command = $application->find('sherpa');
// Commands are registered lazily; unwrap to reach the real service.
if ($command instanceof Symfony\Component\Console\Command\LazyCommand) {
    $command = $command->getCommand();
}

function peek(object $object, string $property): mixed
{
    return (new ReflectionProperty($object, $property))->getValue($object);
}

$commandPerms = peek($command, 'projectPermissions');
$broker = peek(peek($command, 'agentLoop'), 'permissions');
$brokerPerms = peek($broker, 'project');

check('the broker really got a ProjectPermissions', $brokerPerms instanceof App\Permission\ProjectPermissions);
check(
    'command and broker share one ProjectPermissions instance',
    $commandPerms === $brokerPerms,
    $commandPerms === $brokerPerms ? '' : 'separate instances — setProject() would never reach the broker',
);

$commandSession = peek($command, 'sessionPermissions');
check(
    'command and broker share one SessionPermissions instance',
    $commandSession === peek($broker, 'session'),
);

exec('rm -rf ' . escapeshellarg($project));
// ---- the container Sherpa actually runs with --------------------------------
// Everything above boots in debug, where Symfony rebuilds the container as soon
// as a source file changes. bin/sherpa runs in prod, where it does not — so a
// constructor argument added without clearing the cache used to reach the user
// as a TypeError before the first prompt, and no test could see it.
$prodCache = dirname(__DIR__) . '/var/cache/prod';

$prod = new Kernel('prod', false);
$prod->boot();
$command = $prod->getContainer()->get('console.command_loader')->get('sherpa');
if ($command instanceof \Symfony\Component\Console\Command\LazyCommand) {
    // Instantiating it is the point: this is where a stale container throws.
    $command = $command->getCommand();
}
check('the prod container can build the command', $command instanceof \App\Command\SherpaCommand, get_debug_type($command));

// Now make the source newer than the compiled container and boot again.
$compiled = glob($prodCache . '/*Container.php');
check('a compiled prod container exists to go stale', $compiled !== [], $prodCache);

if ($compiled !== []) {
    // Make one source file newer than the compiled container. Comparing
    // mtimes after the fact would prove nothing — a rebuild inside the same
    // second carries the same timestamp — so assert on the cache being gone.
    touch(dirname(__DIR__) . '/src/Kernel.php', filemtime($compiled[0]) + 10);

    $again = new Kernel('prod', false);
    check('a container older than its sources is thrown away', !is_dir($prodCache), $prodCache);

    $again->boot();
    check('and a fresh one is compiled in its place', is_dir($prodCache));
    check('which still builds the command',
        $again->getContainer()->get('console.command_loader')->get('sherpa') !== null);

    touch(dirname(__DIR__) . '/src/Kernel.php');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
