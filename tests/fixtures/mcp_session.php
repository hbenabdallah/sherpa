<?php
// Runs one /mcp command in a session, answering its questions from stdin, and
// prints what happened as JSON — so declaring a server from a session can be
// tested without a terminal.
// Usage: php mcp_session.php <mcp.json> "<args of /mcp>"
// The servers already in the file are loaded first, as at boot.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Agent\MessageBag;
use App\Agent\SystemPromptBuilder;
use App\Agent\Tool\Toolbox;
use App\Command\McpCommands;
use App\Mcp\McpConfig;
use App\Mcp\McpRegistry;
use App\Memory\MemoryStore;
use App\Project\DockerConfig;
use App\Project\Project;
use App\Project\StackDetector;
use App\Skills\SkillRegistry;
use App\TUI\ChatPane;
use App\TUI\LineEditor;
use App\TUI\MarkdownRenderer;
use App\TUI\Terminal;

[, $configPath, $args] = $argv;
$dir = dirname($configPath);

$config = new McpConfig();
$config->setPath($configPath);
$registry = new McpRegistry($config, handshakeTimeout: 5.0, callTimeout: 5.0);
$toolbox = new Toolbox([]);

$memory = new MemoryStore();
$memory->open($dir . '/memory-' . bin2hex(random_bytes(3)) . '.db');
$prompt = new SystemPromptBuilder($memory, new SkillRegistry(), new StackDetector(), null, null, null, $registry);
$project = new Project(
    slug: 'essai', name: 'Essai', path: $dir, memoryDb: $dir . '/memory.db',
    docker: new DockerConfig(enabled: false), stack: '',
    createdAt: new DateTimeImmutable(), lastUsedAt: new DateTimeImmutable(),
);

ob_start();
$registry->load($toolbox);
$bag = new MessageBag();
$bag->system($prompt->build($project));
(new McpCommands($registry, $toolbox, $prompt, new ChatPane(new Terminal(), new MarkdownRenderer()), new LineEditor()))
    ->handle($args, $bag, $project);
$shown = (string) ob_get_clean();
$registry->shutdown();

echo json_encode([
    'shown'  => Terminal::plain($shown),
    'tools'  => array_map(fn($d) => $d->name, $toolbox->getDefinitions()),
    'report' => $registry->report(),
    'prompt' => $bag->all()[0]['content'] ?? '',
]);
