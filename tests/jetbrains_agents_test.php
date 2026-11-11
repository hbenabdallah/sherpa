<?php

declare(strict_types=1);

namespace App\Tests\JetBrainsAgentsTest;

// `sherpa acp --jetbrains`: Sherpa adds itself to ~/.jetbrains/acp.json, the
// JetBrains IDEs' custom agents, instead of the user typing its path there.
// Every file here is one of this test's.

require __DIR__ . '/../vendor/autoload.php';

use App\Acp\JetBrainsAgents;
use App\Command\AcpCommand;
use UnexpectedValueException;

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

$root = sys_get_temp_dir() . '/sherpa-jetbrains-' . bin2hex(random_bytes(4));
mkdir($root, 0777, true);

// ---- no file yet --------------------------------------------------------------
$path = $root . '/fresh/.jetbrains/acp.json';
new JetBrainsAgents($path)->add('/opt/sherpa', ['acp']);
$written = (string) file_get_contents($path);
check('without acp.json, it is created, its directory too', is_file($path));
check(
    'with Sherpa as the IDE expects it, env an empty object',
    $written === "{\n    \"agent_servers\": {\n        \"Sherpa\": {\n            \"command\": \"/opt/sherpa\",\n            \"args\": [\n                \"acp\"\n            ],\n            \"env\": {}\n        }\n    }\n}\n",
    $written,
);

// ---- other agents and settings ------------------------------------------------
$path = $root . '/others/acp.json';
mkdir(dirname($path));
file_put_contents($path, '{"default_mcp_settings": {"use_idea_mcp": true}, "agent_servers": {"Other": {"command": "other", "args": [], "env": {}}, "Sherpa": {"command": "/old/sherpa", "args": ["acp"], "env": {"SHERPA_API_MODEL": "m"}}}}');
new JetBrainsAgents($path)->add('/new/sherpa', ['acp', '-P', 'mistral']);
$config = json_decode((string) file_get_contents($path), true);
check('the other agents stay', ($config['agent_servers']['Other']['command'] ?? null) === 'other', json_encode($config));
check('and so do the other settings', ($config['default_mcp_settings']['use_idea_mcp'] ?? null) === true);
check(
    "Sherpa's earlier entry is replaced, its env kept",
    ($config['agent_servers']['Sherpa'] ?? null) === ['command' => '/new/sherpa', 'args' => ['acp', '-P', 'mistral'], 'env' => ['SHERPA_API_MODEL' => 'm']],
    json_encode($config['agent_servers']['Sherpa'] ?? null),
);
check("an empty object stays one, not a list", str_contains((string) file_get_contents($path), '"env": {}'));
check('nothing is left beside it', glob(dirname($path) . '/acp.json.*') === []);

// ---- a file it cannot read ----------------------------------------------------
$path = $root . '/broken/acp.json';
mkdir(dirname($path));
file_put_contents($path, '{"agent_servers": {');

try {
    new JetBrainsAgents($path)->add('/opt/sherpa', ['acp']);
    check('invalid JSON is refused', false);
} catch (UnexpectedValueException $e) {
    check('invalid JSON is refused, saying which file', str_contains($e->getMessage(), $path), $e->getMessage());
}
check('and left as it was', file_get_contents($path) === '{"agent_servers": {');

// ---- the command --------------------------------------------------------------
$path = $root . '/command/acp.json';
$_SERVER['argv'] = [__DIR__ . '/../bin/sherpa', 'acp', '--jetbrains', '-m', 'devstral'];
ob_start();
$added = AcpCommand::addToJetBrains(new JetBrainsAgents($path));
$said = (string) ob_get_clean();
$sherpa = json_decode((string) file_get_contents($path), true)['agent_servers']['Sherpa'] ?? null;
check('--jetbrains says where it added Sherpa', $added && str_contains($said, $path), $said);
check(
    'from a checkout, PHP runs bin/sherpa, with the options given but --jetbrains',
    $sherpa === ['command' => PHP_BINARY, 'args' => [realpath(__DIR__ . '/../bin/sherpa'), 'acp', '-m', 'devstral'], 'env' => []],
    json_encode($sherpa),
);

exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
