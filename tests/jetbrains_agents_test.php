<?php

declare(strict_types=1);

namespace App\Tests\JetBrainsAgentsTest;

// `sherpa acp --jetbrains`: Sherpa adds itself to ~/.jetbrains/acp.json, the
// JetBrains IDEs' custom agents, instead of the user typing its path there.
// Every file here is one of this test's.

require __DIR__ . '/../vendor/autoload.php';

use App\Acp\JetBrainsAgents;
use App\Command\AcpCommand;
use RuntimeException;
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
check('it is readable by its owner only: an env may hold a key', (fileperms($path) & 0o777) === 0o600, decoct(fileperms($path) & 0o777));

// ---- a symlinked acp.json -----------------------------------------------------
$dotfiles = $root . '/dotfiles/jetbrains';
mkdir($dotfiles, 0777, true);
file_put_contents($dotfiles . '/acp.json', '{"agent_servers": {"Other": {"command": "other", "args": [], "env": {}}}}');
$path = $root . '/linked/.jetbrains/acp.json';
mkdir(dirname($path), 0777, true);
symlink('../../dotfiles/jetbrains/acp.json', $path);
new JetBrainsAgents($path)->add('/opt/sherpa', ['acp']);
$linked = json_decode((string) file_get_contents($dotfiles . '/acp.json'), true);
check('a symlinked acp.json stays a link', is_link($path));
check(
    'and the file it points to gets Sherpa, the other agents kept',
    isset($linked['agent_servers']['Sherpa'], $linked['agent_servers']['Other']),
    json_encode($linked),
);
check('nothing is left beside the target', glob($dotfiles . '/acp.json.*') === [] && glob(dirname($path) . '/acp.json.*') === []);

$path = $root . '/loop/acp.json';
mkdir(dirname($path));
symlink('other.json', $path);
symlink('acp.json', dirname($path) . '/other.json');

try {
    new JetBrainsAgents($path)->add('/opt/sherpa', ['acp']);
    check('a loop of links is refused', false);
} catch (RuntimeException $e) {
    check('a loop of links is refused, saying so', str_contains($e->getMessage(), 'loop'), $e->getMessage());
}
check('and stays a link', is_link($path));

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

// ---- the real command, in Docker or not ---------------------------------------
$home = $root . '/home';
mkdir($home);
$run = proc_open(
    [PHP_BINARY, __DIR__ . '/../bin/sherpa', 'acp', '--jetbrains'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    ['HOME' => $home, 'PATH' => (string) getenv('PATH')],
);
$said = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
$code = proc_close($run);
if (is_file('/.dockerenv')) {
    check('in Docker, it refuses: the host would never see the file', $code !== 0 && str_contains($said, 'by hand'), $said);
    check('and writes nothing', !file_exists($home . '/.jetbrains/acp.json'));
} else {
    check('on the host, sherpa acp --jetbrains adds Sherpa', $code === 0 && is_file($home . '/.jetbrains/acp.json'), $said);
}

exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
