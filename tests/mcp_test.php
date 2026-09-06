<?php
// The MCP client, driven against a server that misbehaves on purpose.
//
// Every server is somebody else's process: it can die at the handshake, print
// junk where a message belongs, or answer nothing at all. None of that may take
// Sherpa with it.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\Tool\Toolbox;
use App\Agent\Tool\ToolCall;
use App\Agent\Tool\Permission;
use App\Mcp\McpClient;
use App\Mcp\McpConfig;
use App\Mcp\McpException;
use App\Mcp\McpRegistry;
use App\Mcp\ServerConfig;
use App\Tool\FileReadTool;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo "        ", substr($detail, 0, 400), "\n";
    }
}

$fixture = dirname(__DIR__) . '/tests/fixtures/mcp_server.php';

function server(string $mode, string $name = 'demo'): ServerConfig
{
    return new ServerConfig(
        name: $name,
        command: PHP_BINARY,
        args: [dirname(__DIR__) . '/tests/fixtures/mcp_server.php', $mode],
    );
}

// ---- the handshake and discovery -------------------------------------------
$client = McpClient::for(server('ok'));
$tools = $client->listTools();

check('the handshake completes and tools come back', count($tools) === 3, json_encode(array_column($tools, 'name')));
check('the negotiated protocol version is recorded', $client->serverProtocolVersion() === '2025-06-18', (string) $client->serverProtocolVersion());
check('a tool keeps its description', str_contains($tools[0]['description'], 'Renvoie le texte'), json_encode($tools[0]));
check('and its input schema', ($tools[0]['schema']['properties']['fois']['type'] ?? null) === 'integer', json_encode($tools[0]['schema']));

// ---- calling ---------------------------------------------------------------
$result = $client->callTool('echo', ['text' => 'bonjour', 'fois' => 2]);
check('a tool call returns its text content', str_contains($result['text'], 'bonjour bonjour'), json_encode($result));
check('and is not flagged as an error', !$result['isError']);

// Content blocks that are not text must be named, not dropped: a tool that
// returned an image would otherwise look like a tool that returned nothing.
check('a non-text content block is named rather than dropped', str_contains($result['text'], 'image'), $result['text']);
$client->close();

// ---- a server that talks over the protocol ---------------------------------
// Banners on stdout, logs on stderr. Both are common, and stderr left undrained
// blocks the very process we are waiting on once its pipe buffer fills.
$noisy = McpClient::for(server('noisy'));
check('junk printed on stdout does not break the protocol', count($noisy->listTools()) === 3);
check('and a call still works through the noise', str_contains($noisy->callTool('echo', ['text' => 'ok'])['text'], 'ok'));
$noisy->close();

// ---- pagination ------------------------------------------------------------
// Stopping at the first page would hide tools without saying so.
$paged = McpClient::for(server('paginated'));
check('every page of tools/list is followed', count($paged->listTools()) === 3, json_encode(array_column($paged->listTools(), 'name')));
$paged->close();

// ---- servers that fail ------------------------------------------------------
$missing = McpClient::for(new ServerConfig(name: 'absent', command: 'nexistepasdutout'));
$threw = false;
try { $missing->listTools(); } catch (McpException $e) { $threw = true; $message = $e->getMessage(); }
check('a missing command is reported before anything else', $threw && str_contains($message ?? '', 'introuvable'), $message ?? '');

$crashing = McpClient::for(server('crash'));
$threw = false;
try { $crashing->listTools(); } catch (McpException $e) { $threw = true; $message = $e->getMessage(); }
check('a server that dies at the handshake is reported', $threw, $message ?? '');
check("and its stderr is quoted, since that is where the reason is", str_contains($message ?? '', 'configuration manquante'), $message ?? '');
$crashing->close();

// A tool call that errors is an answer, not a crash: the model has to read it.
$broken = McpClient::for(server('toolerror'));
$result = $broken->callTool('echo', ['text' => 'x']);
check('a tool-level error comes back flagged', $result['isError'] && str_contains($result['text'], 'existe pas'), json_encode($result));
$broken->close();

$protocolError = McpClient::for(server('protoerror'));
$threw = false;
try { $protocolError->callTool('echo', ['text' => 'x']); } catch (McpException $e) { $threw = true; $message = $e->getMessage(); }
check('a JSON-RPC error is raised rather than returned as content', $threw && str_contains($message ?? '', 'invalide'), $message ?? '');
$protocolError->close();

// A server that accepts the handshake and then answers nothing must not hold
// the session open forever. Unlike local inference, this is not a wait anyone
// chose to accept.
$hanging = McpClient::for(server('silent'), handshakeTimeout: 1.0, callTimeout: 1.0);
$started = microtime(true);
$threw = false;
try { $hanging->listTools(); } catch (McpException $e) { $threw = true; $message = $e->getMessage(); }
$waited = microtime(true) - $started;
check('a server that never answers times out', $threw && $waited < 5.0, sprintf('%.1fs: %s', $waited, $message ?? 'no throw'));
$hanging->close();

// ---- the registry ----------------------------------------------------------

function writeConfig(array $servers): string
{
    $path = sys_get_temp_dir() . '/mcp-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode(['mcpServers' => $servers], JSON_PRETTY_PRINT));

    return $path;
}

function registryFor(string $path): array
{
    $config = new McpConfig();
    $config->setPath($path);
    $registry = new McpRegistry($config, handshakeTimeout: 5.0, callTimeout: 5.0);
    $toolbox = new Toolbox([new FileReadTool()]);
    $registry->load($toolbox);

    return [$registry, $toolbox];
}

$fixtureArgs = fn(string $mode) => [dirname(__DIR__) . '/tests/fixtures/mcp_server.php', $mode];

$path = writeConfig([
    'demo' => ['command' => PHP_BINARY, 'args' => $fixtureArgs('ok')],
]);
[$registry, $toolbox] = registryFor($path);

$names = array_map(fn($d) => $d->name, $toolbox->getDefinitions());
check('remote tools are namespaced under their server', in_array('mcp__demo__echo', $names, true), implode(',', $names));

// The fixture deliberately exposes a tool called file_read. Shadowing the
// agent's own file access with somebody else's process is not a name clash,
// it is a redirection.
check('a native tool is not shadowed by a remote one of the same name',
    $toolbox->find('file_read')?->handler instanceof FileReadTool, get_debug_type($toolbox->find('file_read')?->handler));
check('the remote one is still reachable under its own name',
    $toolbox->find('mcp__demo__file_read') !== null, implode(',', $names));

$definition = $toolbox->find('mcp__demo__echo');
check('a remote tool asks before it runs', $definition?->permission === Permission::CONFIRM);
check('its description says where it comes from', str_contains($definition?->description ?? '', 'MCP · demo'), $definition?->description ?? '');
check('its schema survives the trip', ($definition?->parameters['fois']['type'] ?? null) === 'integer', json_encode($definition?->parameters));
check('and so does which arguments are required',
    ($definition?->parameters['text']['required'] ?? null) === true && ($definition?->parameters['fois']['required'] ?? null) === false,
    json_encode($definition?->parameters));

// Through the Toolbox, which is how the agent actually calls it — including the
// argument coercion, since a model writes "2" as readily as 2.
$result = $toolbox->execute(new ToolCall(id: 'c1', name: 'mcp__demo__echo', arguments: ['text' => 'salut', 'fois' => '2']));
check('the agent can call a remote tool end to end', !$result->isError && str_contains($result->content, 'salut salut'), $result->content);

$registry->shutdown();
unlink($path);

// ---- one bad server does not take the others with it -----------------------
$path = writeConfig([
    'mort'  => ['command' => PHP_BINARY, 'args' => $fixtureArgs('crash')],
    'vivant' => ['command' => PHP_BINARY, 'args' => $fixtureArgs('ok')],
    'eteint' => ['command' => PHP_BINARY, 'args' => $fixtureArgs('ok'), 'enabled' => false],
]);
[$registry, $toolbox] = registryFor($path);

$names = array_map(fn($d) => $d->name, $toolbox->getDefinitions());
check('a working server still loads after a broken one', in_array('mcp__vivant__echo', $names, true), implode(',', $names));
check('the broken one contributes nothing', !in_array('mcp__mort__echo', $names, true), implode(',', $names));

$report = implode("\n", $registry->report());
check('the failure is named in the report', str_contains($report, 'mort'), $report);
check('a disabled server is listed as disabled, not as broken', str_contains($report, 'eteint : désactivé'), $report);
$registry->shutdown();
unlink($path);

// Namespacing removes the clash with native tools, but not between servers
// whose names normalise to the same thing.
$path = writeConfig([
    'demo'   => ['command' => PHP_BINARY, 'args' => $fixtureArgs('ok')],
    'Demo !' => ['command' => PHP_BINARY, 'args' => $fixtureArgs('ok')],
]);
[$registry, $toolbox] = registryFor($path);

$names = array_map(fn($d) => $d->name, $toolbox->getDefinitions());
check('two servers cannot both claim one tool name', count(array_filter($names, fn($n) => $n === 'mcp__demo__echo')) === 1, implode(',', $names));

$report = implode("\n", $registry->report());
check('and the one that lost is named rather than passed over',
    str_contains($report, 'ignorés') && str_contains($report, 'echo'), $report);
$registry->shutdown();
unlink($path);

// ---- configuration problems are said out loud ------------------------------
$path = sys_get_temp_dir() . '/mcp-bad-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($path, '{ ceci ne parse pas');
[$registry, $toolbox] = registryFor($path);
check('an unparseable config is reported, not ignored',
    str_contains(implode(' ', $registry->report()), 'invalide'), implode(' | ', $registry->report()));
unlink($path);

file_put_contents($path, json_encode(['mcpServers' => ['sansCommande' => ['args' => ['x']]]]));
[$registry, $toolbox] = registryFor($path);
check('a server with no command is reported by name',
    str_contains(implode(' ', $registry->report()), 'sansCommande'), implode(' | ', $registry->report()));
unlink($path);

$config = new McpConfig();
$config->setPath(sys_get_temp_dir() . '/mcp-absent-' . bin2hex(random_bytes(4)) . '.json');
check('no config file at all is not a problem', $config->servers() === []);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
