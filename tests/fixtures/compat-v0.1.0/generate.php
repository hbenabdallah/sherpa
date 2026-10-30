<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\CompatV010\Generate;

// Writes the files Sherpa v0.1.0 kept, with v0.1.0's own code, for
// compat_test.php. In the container:
//   mkdir -p var/compat && git archive v0.1.0 src | tar -x -C var/compat
//   php tests/fixtures/compat-v0.1.0/generate.php var/compat/src
// then copy config.yaml, projects.yaml, memory.db and the session from
// /tmp/compat-home/.config/sherpa here.

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// After Composer's loader, and ahead of it: App\ comes from the old sources.
$old = $argv[1] ?? exit("usage: generate.php <v0.1.0 src directory>\n");
spl_autoload_register(function (string $class) use ($old) {
    if (str_starts_with($class, 'App\\')) {
        $file = $old . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, true, true);

use App\Config\Backend;
use App\Config\MachineConfig;
use App\Config\ModelProfile;
use App\Memory\ContextStore;
use App\Memory\MemoryStore;
use App\Project\DockerConfig;
use App\Project\ProjectStore;
use App\Project\StackDetector;
use App\Session\SessionStore;

$home = '/tmp/compat-home';
exec('rm -rf ' . $home);
mkdir($home . '/.config/sherpa', 0777, true);
mkdir($home . '/projet', 0777, true);
file_put_contents($home . '/projet/composer.json', '{"require":{"php":">=8.2"}}');
$_SERVER['HOME'] = $home;
putenv('HOME=' . $home);

$config = new MachineConfig();
$config->save(new ModelProfile(model: 'qwen/qwen3-coder', contextWindow: 65536, source: 'test', backend: Backend::Api), true);
$config->saveApiUrl('https://openrouter.ai/api/v1');
$config->saveEmbeddingModel(Backend::Api, 'text-embedding-3-small');
$config->save(new ModelProfile(model: 'qwen2.5-coder:7b', contextWindow: 32768, source: 'test', backend: Backend::Ollama), false);

$projects = new ProjectStore(new StackDetector());
$project = $projects->create('Projet Été', $home . '/projet', new DockerConfig(enabled: true, container: 'app', dbContainer: 'db'));
$projects->allowTool($project, 'shell_exec');

$memory = new MemoryStore();
$memory->open($project->memoryDb);
$memory->remember('tests', 'make test runs the suites in Docker');
$memory->remember('db', 'PostgreSQL 16');
$memory->supersede('db', 'PostgreSQL 17');

$excerpts = new ContextStore();
$excerpts->open();
$id = $excerpts->keep('file_read', "class Invoice\n{\n    public int \$subtotal;\n}");

$sessions = new SessionStore();
$sessions->bind($project);
$sessions->save([
    ['role' => 'system', 'content' => 'SYSTEM'],
    ['role' => 'user', 'content' => 'Où est calculée la TVA ?'],
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call00001', 'type' => 'function', 'function' => ['name' => 'file_read', 'arguments' => ['path' => 'src/Invoice.php']]]]],
    ['role' => 'tool', 'content' => "[Compacted: context_recall(id: {$id})]", 'name' => 'file_read', 'tool_call_id' => 'call00001'],
    ['role' => 'assistant', 'content' => 'Dans TaxCalculator.'],
], $excerpts->export(), 'api', 'qwen/qwen3-coder');

echo implode("\n", array_map(fn($f) => substr($f, strlen($home)), explode("\n", trim(shell_exec("find $home -type f"))))), "\n";
