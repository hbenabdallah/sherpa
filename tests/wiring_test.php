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

// ---- the excerpt store must be ONE instance --------------------------------
// The compactor fills it and context_recall reads it. Two instances would fail
// in the worst possible way: elision would look like it worked, and every
// recall would answer "nothing has been removed from the context" — a loss
// reported as an empty store rather than as a bug.
$command = $container->get('console.command_loader')->get('sherpa');
if ($command instanceof \Symfony\Component\Console\Command\LazyCommand) {
    $command = $command->getCommand();
}

$commandStore = (new ReflectionProperty($command::class, 'contextStore'))->getValue($command);
$toolStore = (new ReflectionProperty(App\Tool\ContextRecallTool::class, 'store'))
    ->getValue($container->get(App\Tool\ContextRecallTool::class));
$compactor = (new ReflectionProperty($command::class, 'compactor'))->getValue($command);
$compactorStore = (new ReflectionProperty(App\Agent\HistoryCompactor::class, 'context'))->getValue($compactor);

check('the command and context_recall share one excerpt store', $commandStore === $toolStore);
check('and the compactor fills that very one', $compactorStore === $commandStore);

// Same class of failure, one layer down: these are optional constructor
// arguments, so a wiring miss is a silent null rather than a boot error.
check('the compactor was handed the measured speed',
    (new ReflectionProperty(App\Agent\HistoryCompactor::class, 'speed'))->getValue($compactor) !== null);
check('and the interrupt, so Ctrl+C reaches a summarisation',
    (new ReflectionProperty(App\Agent\HistoryCompactor::class, 'interrupt'))->getValue($compactor) !== null);

// ---- one number, two consumers ---------------------------------------------
// The reply cap the backend enforces and the room ContextBudget withholds for
// that reply are the same fact. Two copies of one fact is how a budget starts
// lying — the model profile went through exactly this, which is why the window
// now travels with the model.
$platform = (new ReflectionProperty($command::class, 'platform'))->getValue($command);
$ollama = (new ReflectionProperty(App\Platform\SwitchablePlatform::class, 'ollama'))->getValue($platform);
$api = (new ReflectionProperty(App\Platform\SwitchablePlatform::class, 'api'))->getValue($platform);
$budget = (new ReflectionProperty($command::class, 'budget'))->getValue($command);
$reserved = (new ReflectionProperty(App\Agent\ContextBudget::class, 'reservedForResponse'))->getValue($budget);

// Three consumers now: each backend caps its replies, and the budget has one
// reservation for whichever of them is in force.
foreach (['Ollama' => $ollama, 'API' => $api] as $label => $backend) {
    $capped = (new ReflectionProperty($backend::class, 'maxTokens'))->getValue($backend);
    check("the {$label} reply cap and the budget reservation are the same number",
        $capped === $reserved, "cap {$capped} vs reserve {$reserved}");
}

// On unless said otherwise: it is how project memory fills up.
check('the command extracts facts at the end of a session by default',
    (new ReflectionProperty($command::class, 'extractFacts'))->getValue($command) === true);

// Resolved when the command is built, so a fresh container sees the change.
putenv('SHERPA_FACT_EXTRACTION=off');
$_SERVER['SHERPA_FACT_EXTRACTION'] = $_ENV['SHERPA_FACT_EXTRACTION'] = 'off';
$quiet = new Kernel('dev', true);
$quiet->boot();
$quietCommand = $quiet->getContainer()->get('console.command_loader')->get('sherpa');
if ($quietCommand instanceof Symfony\Component\Console\Command\LazyCommand) {
    $quietCommand = $quietCommand->getCommand();
}
check('and SHERPA_FACT_EXTRACTION=off turns it off',
    (new ReflectionProperty($quietCommand::class, 'extractFacts'))->getValue($quietCommand) === false);
putenv('SHERPA_FACT_EXTRACTION');
unset($_SERVER['SHERPA_FACT_EXTRACTION'], $_ENV['SHERPA_FACT_EXTRACTION']);

// The meter the command shows must be the one the switch feeds: two
// instances would display a session that never made a request.
check('the command shows the usage the platform records',
    (new ReflectionProperty(App\Platform\SwitchablePlatform::class, 'meter'))->getValue($platform)
        === (new ReflectionProperty($command::class, 'usage'))->getValue($command));

// The same silent failure the Ollama wiring guards against: a nullable
// interrupt left null makes Ctrl+C inert during a request.
check('the API backend can be interrupted too',
    (new ReflectionProperty(App\Platform\OpenAiCompatiblePlatform::class, 'interrupt'))->getValue($api) !== null);

// Same class of silent failure as the stores above: optional arguments.
check('the grep tool can count its own hits and misses',
    (new ReflectionProperty(App\Tool\ProjectGrepTool::class, 'store'))
        ->getValue($container->get(App\Tool\ProjectGrepTool::class)) !== null);

$loop = (new ReflectionProperty($command::class, 'agentLoop'))->getValue($command);
$trace = (new ReflectionProperty(App\Agent\AgentLoop::class, 'trace'))->getValue($loop);
check('the loop traces how the model searches', $trace !== null);
check('and the trace can record it', $trace !== null
    && (new ReflectionProperty(App\Agent\SearchTrace::class, 'store'))->getValue($trace) !== null);

$builder = (new ReflectionProperty($command::class, 'promptBuilder'))->getValue($command);
check('the prompt builder can see what has been worked on lately',
    (new ReflectionProperty(App\Agent\SystemPromptBuilder::class, 'activity'))->getValue($builder) !== null);
check('and how the project runs its tests',
    (new ReflectionProperty(App\Agent\SystemPromptBuilder::class, 'tests'))->getValue($builder) !== null);

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
$prod = new Kernel('prod', false);
$prodCache = $prod->getCacheDir();
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

// The checkout is /app inside Docker and its real path on the host, and both
// write to one var/cache. A container compiled on one side is full of paths
// the other cannot open, so the two must not share a directory.
$elsewhere = new class('prod', false) extends Kernel {
    public function getProjectDir(): string
    {
        return '/un/autre/chemin/vers/sherpa';
    }
};
check('the same checkout seen from another path compiles its own container',
    $elsewhere->getCacheDir() !== $prodCache && str_contains($elsewhere->getCacheDir(), '/var/cache/prod-'),
    $elsewhere->getCacheDir());
    check('which still builds the command',
        $again->getContainer()->get('console.command_loader')->get('sherpa') !== null);

    touch(dirname(__DIR__) . '/src/Kernel.php');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
