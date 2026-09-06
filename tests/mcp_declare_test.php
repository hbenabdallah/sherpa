<?php
// Declaring MCP servers without opening mcp.json: /mcp add and its siblings in
// a session, `sherpa mcp …` in the shell — the same file written either way,
// and a session that takes the change at once.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\Tool\Toolbox;
use App\Command\McpCommand;
use App\Mcp\CommandLine;
use App\Mcp\McpConfig;
use App\Mcp\McpException;
use App\Mcp\McpRegistry;
use App\Mcp\ServerConfig;
use App\TUI\Terminal;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr($detail, 0, 600), "\n";
    }
}

$dir = sys_get_temp_dir() . '/sherpa-mcp-declare-' . bin2hex(random_bytes(4));
mkdir($dir, 0777, true);
$fixture = __DIR__ . '/fixtures/mcp_server.php';

function configAt(string $path): McpConfig
{
    $config = new McpConfig();
    $config->setPath($path);

    return $config;
}

// ---- a command line, cut as a shell would ---------------------------------------
check('words are cut on spaces', CommandLine::split('npx -y  server') === ['npx', '-y', 'server']);
check('single quotes keep everything as it is',
    CommandLine::split("sh -c 'exec docker run -v \"\$PWD:\$PWD\" img'") === ['sh', '-c', 'exec docker run -v "$PWD:$PWD" img'],
    json_encode(CommandLine::split("sh -c 'exec docker run -v \"\$PWD:\$PWD\" img'")));
check('double quotes group, and unescape what sh unescapes',
    CommandLine::split('echo "a b \\"c\\" \\n"') === ['echo', 'a b "c" \\n'], json_encode(CommandLine::split('echo "a b \\"c\\" \\n"')));
check('a backslash escapes a space', CommandLine::split('/My\\ Apps/server --x') === ['/My Apps/server', '--x']);
check('an empty quoted word is a word', CommandLine::split("cmd ''") === ['cmd', '']);
check('nothing is expanded: no variable, no glob', CommandLine::split('ls $HOME *.php') === ['ls', '$HOME', '*.php']);
$threw = false;
try { CommandLine::split("sh -c 'oops"); } catch (McpException) { $threw = true; }
check('a quote left open is refused rather than swallowing the line', $threw);

// ---- the file, written --------------------------------------------------------
$path = $dir . '/conf/mcp.json';
$config = configAt($path);
$config->save(ServerConfig::fromArray('demo', ['command' => PHP_BINARY, 'args' => [$fixture, 'ok'], 'env' => ['TOKEN' => 's3cret']]));
check('a first server creates the file, and its directory', is_file($path));
check('readable by its owner only, since it holds tokens', (fileperms($path) & 0777) === 0600, decoct(fileperms($path) & 0777));
check('and reads back as it was declared', $config->server('demo')?->env === ['TOKEN' => 's3cret'] && $config->server('demo')?->args === [$fixture, 'ok']);

file_put_contents($path, json_encode([
    '_comment'   => ['kept'],
    'mcpServers' => ['autre' => ['url' => 'https://example.test/mcp', 'custom' => 'kept too']],
], JSON_PRETTY_PRINT));
$config->save(ServerConfig::fromArray('demo', ['command' => PHP_BINARY, 'args' => [$fixture, 'ok']]));
$data = json_decode((string) file_get_contents($path), true);
check('the rest of the file is kept: comments, other servers, keys Sherpa does not know',
    ($data['_comment'] ?? null) === ['kept'] && ($data['mcpServers']['autre']['custom'] ?? null) === 'kept too' && isset($data['mcpServers']['demo']),
    json_encode($data));
check('nothing empty or default is written', !isset($data['mcpServers']['demo']['env']) && !isset($data['mcpServers']['demo']['enabled']), json_encode($data['mcpServers']['demo']));

check('turning one off keeps how it was set up', $config->setEnabled('demo', false) && $config->server('demo')?->enabled === false && $config->server('demo')?->command === PHP_BINARY);
check('and back on', $config->setEnabled('demo', true) && $config->server('demo')?->enabled === true);
check('a name nobody declared is not invented', !$config->setEnabled('personne', false) && !$config->remove('personne'));
check('removing one leaves the others', $config->remove('autre') && $config->server('autre') === null && $config->server('demo') !== null);

$threw = false;
try { $config->save(new ServerConfig(name: 'mon serveur', command: 'x')); } catch (McpException) { $threw = true; }
check('a name that cannot prefix a tool is refused', $threw);

$servers = configAt($dir . '/servers.json');
file_put_contents($dir . '/servers.json', json_encode(['servers' => ['a' => ['command' => 'x']]]));
$servers->save(ServerConfig::fromArray('b', ['command' => 'y']));
$data = json_decode((string) file_get_contents($dir . '/servers.json'), true);
check('a file that says "servers" is written under "servers"', isset($data['servers']['a'], $data['servers']['b']) && !isset($data['mcpServers']), json_encode($data));

// ---- a session takes a server in, and lets it go ---------------------------------
$config = configAt($dir . '/live.json');
$registry = new McpRegistry($config, handshakeTimeout: 5.0, callTimeout: 5.0);
$toolbox = new Toolbox([]);
$registry->load($toolbox);
check('no server, no tool', $toolbox->getDefinitions() === [] && $registry->promptSection() === '');

$line = $registry->connect(ServerConfig::fromArray('demo', ['command' => PHP_BINARY, 'args' => [$fixture, 'ok']]), $toolbox);
$names = array_map(fn($d) => $d->name, $toolbox->getDefinitions());
check('connected mid-session, its tools are there at once', in_array('mcp__demo__echo', $names, true), implode(',', $names));
check('with its line in the report', str_contains($line, 'demo: 3 tools') && $registry->report() === [$line], json_encode($registry->report()));
check('and its instructions in the prompt section', str_contains($registry->promptSection(), 'Call horloge'));

$registry->connect(ServerConfig::fromArray('demo', ['command' => PHP_BINARY, 'args' => [$fixture, 'ok']]), $toolbox);
check('connecting it again replaces it rather than clashing', count($toolbox->getDefinitions()) === 3 && count($registry->report()) === 1);

$registry->disconnect('demo', $toolbox, 'demo: disabled');
check('let go, its tools leave the Toolbox', $toolbox->getDefinitions() === [], implode(',', array_map(fn($d) => $d->name, $toolbox->getDefinitions())));
check('its instructions leave the prompt', $registry->promptSection() === '');
check('and its line says why', $registry->report() === ['demo: disabled'] && !$registry->isConnected('demo'));

$threw = false;
try {
    $registry->connect(ServerConfig::fromArray('mort', ['command' => PHP_BINARY, 'args' => [$fixture, 'crash']]), $toolbox);
} catch (McpException) {
    $threw = true;
}
check('a server that fails mid-session throws, and leaves no tool behind', $threw && $toolbox->getDefinitions() === [] && !$registry->isConnected('mort'));
$registry->shutdown();

// ---- /mcp in a session ------------------------------------------------------------
$session = function (string $config, string $args, string $answers = ''): array {
    $process = new Process([PHP_BINARY, __DIR__ . '/fixtures/mcp_session.php', $config, $args], input: $answers, timeout: 60);
    $process->run();
    $data = json_decode($process->getOutput(), true);

    return is_array($data) ? $data : ['shown' => $process->getOutput() . $process->getErrorOutput(), 'tools' => [], 'report' => [], 'prompt' => ''];
};

$live = $dir . '/session/mcp.json';
$r = $session($live, '');
check('/mcp with nothing declared says how to declare one', str_contains($r['shown'], '/mcp add') && str_contains($r['shown'], 'mcp.json'), $r['shown']);

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ok';
$r = $session($live, 'add', "demo\n{$command}\nTOKEN\ns3cret\n\n");
check('/mcp add: name, command, a variable — and the server answers', str_contains($r['shown'], '✓ demo: 3 tools'), $r['shown']);
check('its tools are in the session at once', in_array('mcp__demo__echo', $r['tools'], true), json_encode($r['tools']));
check('the system prompt is rebuilt with its instructions', str_contains($r['prompt'], 'Call horloge before echo'), substr($r['prompt'], -600));
$saved = configAt($live)->server('demo');
check('it is saved, the command cut into its words', $saved?->command === PHP_BINARY && $saved?->args === [$fixture, 'ok'], json_encode($saved?->toArray()));
check('with the variable typed hidden', $saved?->env === ['TOKEN' => 's3cret'] && !str_contains($r['shown'], 's3cret'), $r['shown']);

$r = $session($live, 'add', "Demo !\ndemo\nn\n\n");
check('a name that cannot prefix tools is asked again', str_contains($r['shown'], 'letters, digits'), $r['shown']);
check('one already taken is not replaced without a yes', str_contains($r['shown'], 'exists already') && str_contains($r['shown'], 'No MCP server added'), $r['shown']);

$crash = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' crash';
$r = $session($live, 'add', "mort\n{$crash}\n\nn\n");
check('a server that does not answer is said so, and not saved without a yes',
    str_contains($r['shown'], 'missing configuration') && configAt($live)->server('mort') === null && !in_array('mcp__mort__echo', $r['tools'], true), $r['shown']);
$r = $session($live, 'add', "mort\n{$crash}\n\ny\n");
check('saved anyway when asked to', configAt($live)->server('mort') !== null && str_contains($r['shown'], 'not connected'), $r['shown']);

$r = $session($live, 'add', "web\nhttps://example.test/mcp\nBearer xyz\nn\n");
check('an address asks for its Authorization header, hidden', str_contains($r['shown'], 'Authorization header') && !str_contains($r['shown'], 'xyz'), $r['shown']);

$r = $session($live, 'disable demo');
check('/mcp disable takes its tools out of the session', !in_array('mcp__demo__echo', $r['tools'], true) && in_array('demo: disabled', $r['report'], true), json_encode($r));
check('and its instructions out of the prompt', !str_contains($r['prompt'], 'Call horloge'));
check('the file keeps it, off', configAt($live)->server('demo')?->enabled === false);

$r = $session($live, 'enable demo');
check('/mcp enable brings it back at once', in_array('mcp__demo__echo', $r['tools'], true) && str_contains($r['prompt'], 'Call horloge'), json_encode($r['tools']));

$r = $session($live, 'remove mort');
check('/mcp remove forgets it', configAt($live)->server('mort') === null && str_contains($r['shown'], 'mort removed'), $r['shown']);
$r = $session($live, 'remove personne');
check('a name nobody declared is said to be unknown', str_contains($r['shown'], 'No MCP server named personne'), $r['shown']);
$r = $session($live, 'disable');
check('a verb without a name asks which one', str_contains($r['shown'], 'Which one?'), $r['shown']);

// ---- sherpa mcp, from the shell --------------------------------------------------
$shell = $dir . '/shell/mcp.json';
$tester = new CommandTester(new McpCommand(configAt($shell)));

$tester->execute(['action' => 'add', 'name' => 'demo', 'server-command' => [PHP_BINARY, $fixture, 'ok'], '--var' => ['TOKEN=s3cret']]);
$out = Terminal::plain($tester->getDisplay());
check('sherpa mcp add tries the server, then saves it', $tester->getStatusCode() === 0 && str_contains($out, 'demo answers: 3 tools') && configAt($shell)->server('demo')?->env === ['TOKEN' => 's3cret'], $out);

$tester->execute(['action' => 'add', 'name' => 'web', '--url' => 'https://example.test/mcp', '--header' => ['Authorization: Bearer xyz'], '--no-check' => true]);
check('--no-check saves without trying', $tester->getStatusCode() === 0 && configAt($shell)->server('web')?->headers === ['Authorization' => 'Bearer xyz']);

$tester->execute(['action' => 'add', 'name' => 'x', '--url' => 'https://example.test/mcp', 'server-command' => ['php']]);
check('a command and --url together are refused', $tester->getStatusCode() === 1 && configAt($shell)->server('x') === null);
$tester->execute(['action' => 'add', 'name' => 'y', 'server-command' => ['php'], '--var' => ['SANS_EGAL'], '--no-check' => true]);
check('a variable without = is refused', $tester->getStatusCode() === 1 && str_contains($tester->getDisplay(), 'KEY=value'), $tester->getDisplay());

$tester->execute(['action' => 'list']);
$out = Terminal::plain($tester->getDisplay());
check('sherpa mcp list shows each server and how it starts', str_contains($out, 'demo') && str_contains($out, 'mcp_server.php ok') && str_contains($out, 'https://example.test/mcp'), $out);
check('naming its variables and headers, never their values', str_contains($out, 'env: TOKEN') && str_contains($out, 'headers: Authorization') && !str_contains($out, 's3cret') && !str_contains($out, 'xyz'), $out);

$tester->execute(['action' => 'disable', 'name' => 'web']);
check('sherpa mcp disable', configAt($shell)->server('web')?->enabled === false);
$tester->execute(['action' => 'remove', 'name' => 'web']);
check('sherpa mcp remove', configAt($shell)->server('web') === null && $tester->getStatusCode() === 0);
$tester->execute(['action' => 'remove', 'name' => 'web']);
check('and again, an error: nothing by that name', $tester->getStatusCode() === 1);
$tester->execute(['action' => 'frobnicate']);
check('an unknown action is refused', $tester->getStatusCode() === 1);

(new Process(['rm', '-rf', $dir]))->run();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
