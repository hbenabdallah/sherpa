<?php
// Several API providers on one machine: recorded once, switched between at
// will. Each names the variable its key is in, and the one thing that must
// never happen is a provider receiving another one's key.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Backend;
use App\Config\MachineConfig;
use App\Config\ModelProfile;
use App\Config\ModelResolver;
use App\Config\ProviderName;
use App\TUI\Terminal;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr(Terminal::plain($detail), 0, 600), "\n";
    }
}

$dir = sys_get_temp_dir() . '/sherpa-provider-' . bin2hex(random_bytes(4));
mkdir($dir, 0777, true);

function configAt(string $path): MachineConfig
{
    $config = new MachineConfig();
    $config->setPath($path);

    return $config;
}

// ---- names, suggested from the address ---------------------------------------
check('api.groq.com is "groq"', ProviderName::fromUrl('https://api.groq.com/openai/v1') === 'groq');
check('api.openai.com is "openai"', ProviderName::fromUrl('https://api.openai.com/v1') === 'openai');
check('a Google address is named after its domain',
    ProviderName::fromUrl('https://generativelanguage.googleapis.com/v1beta/openai') === 'googleapis');
check('a local server is "localhost"', ProviderName::fromUrl('http://localhost:8000/v1') === 'localhost');
check('no address is "default"', ProviderName::fromUrl(null) === 'default');
check('a typed name is lowered', ProviderName::normalize(' Groq ') === 'groq');
check('and one with spaces refused', ProviderName::normalize('my groq') === null);
check('the key variable follows the name', ProviderName::keyVariable('my-groq') === 'MY_GROQ_API_KEY');

// ---- a file from before providers ---------------------------------------------
file_put_contents($dir . '/flat.yaml', "backend: api\napi:\n  url: 'https://api.cloudflare.com/client/v4/accounts/x/ai/v1'\n"
    . "  model: '@cf/zai/glm'\n  context: 32768\n  embedding_model: '@cf/baai/bge-m3'\n");
$flat = configAt($dir . '/flat.yaml');
check('a flat api section reads as one provider, named after its address',
    array_keys($flat->providers()) === ['cloudflare'] && $flat->activeProvider() === 'cloudflare', json_encode($flat->providers()));
check('with its model, address and embedding model intact',
    $flat->read(Backend::Api)?->model === '@cf/zai/glm' && $flat->apiUrl() === 'https://api.cloudflare.com/client/v4/accounts/x/ai/v1'
    && $flat->embeddingModel(Backend::Api) === '@cf/baai/bge-m3');
check('and no key variable: it uses SHERPA_API_KEY', $flat->apiKeyVariable() === null);

$flat->addProvider('groq', 'https://api.groq.com/openai/v1', 'GROQ_API_KEY');
$written = Yaml::parseFile($dir . '/flat.yaml');
check('the next write moves it into the new shape',
    ($written['api']['providers']['cloudflare']['model'] ?? null) === '@cf/zai/glm' && !isset($written['api']['url']), json_encode($written));
check('adding a provider does not switch to it', $flat->activeProvider() === 'cloudflare');
check('the new one names its key variable, never a key',
    ($written['api']['providers']['groq']['key_env'] ?? null) === 'GROQ_API_KEY' && !str_contains((string) file_get_contents($dir . '/flat.yaml'), 'gsk_'));

// ---- each provider keeps its own choices --------------------------------------
$flat->useProvider('groq');
check('a provider named for this run is the one in force', $flat->activeProvider() === 'groq' && $flat->providerChosenForRun());
check('a new provider has no model yet', $flat->read(Backend::Api) === null);
check('its address and key variable are its own',
    $flat->apiUrl() === 'https://api.groq.com/openai/v1' && $flat->apiKeyVariable() === 'GROQ_API_KEY');
check('and it has settled no embedding model', !$flat->hasEmbeddingChoice(Backend::Api));

$flat->save(new ModelProfile('openai/gpt-oss-120b', 65536, '', Backend::Api), asDefault: false);
check('a model saved for this run only goes to that provider', $flat->read(Backend::Api)?->model === 'openai/gpt-oss-120b');
check('without making it the machine\'s', (Yaml::parseFile($dir . '/flat.yaml')['api']['provider'] ?? null) === 'cloudflare');
$flat->useProvider(null);
check('the machine\'s provider kept its own model', $flat->read(Backend::Api)?->model === '@cf/zai/glm');

$flat->useProvider('groq');
$flat->save(new ModelProfile('openai/gpt-oss-120b', 65536, '', Backend::Api));
$flat->useProvider(null);
check('a model kept for this machine keeps its provider too', $flat->activeProvider() === 'groq');

check('the provider in force cannot be removed', !$flat->removeProvider('groq') && isset($flat->providers()['groq']));
check('another one can', $flat->removeProvider('cloudflare') && array_keys($flat->providers()) === ['groq']);
check('and an unknown one is refused', !$flat->removeProvider('absent'));

// ---- which address and which key ---------------------------------------------
$two = configAt($dir . '/two.yaml');
$two->addProvider('one', 'https://api.one.test/v1', null);
$two->addProvider('two', 'https://api.two.test/v1', 'SHERPA_TEST_TWO_KEY');
$resolver = new ModelResolver($two, 'env:7b', 32768);

check('SHERPA_API_URL still wins over the machine\'s provider',
    $resolver->apiUrl('https://env.test/v1') === 'https://env.test/v1');
check('and an address that is no provider\'s uses SHERPA_API_KEY', $resolver->apiKey('https://env.test/v1') === null);
check('a provider without a key variable uses SHERPA_API_KEY', $resolver->apiKey('https://api.one.test/v1') === null);

$resolver->useProvider('two');
check('a provider named for the run wins over SHERPA_API_URL',
    $resolver->apiUrl('https://env.test/v1') === 'https://api.two.test/v1');
putenv('SHERPA_TEST_TWO_KEY=k-two');
check('its key comes from its own variable', $resolver->apiKey('https://api.two.test/v1') === 'k-two');
putenv('SHERPA_TEST_TWO_KEY');
check('and is empty, not SHERPA_API_KEY, when that variable is unset', $resolver->apiKey('https://api.two.test/v1') === '');

// ---- /provider, run as the user would ------------------------------------------
/** @param array<string, string|false> $env */
function provider(string $config, string $args, string $input = '', array $env = []): array
{
    $process = new Process(
        [PHP_BINARY, __DIR__ . '/fixtures/provider_session.php', $config, $args],
        env: $env + ['SHERPA_API_KEY' => 'k-one', 'TWO_API_KEY' => false, 'GROQ_API_KEY' => false],
        input: $input,
        timeout: 20,
    );
    $process->run();

    return json_decode($process->getOutput(), true) ?? ['error' => $process->getErrorOutput() . $process->getOutput()];
}

function twoProviders(string $path): string
{
    file_put_contents($path, Yaml::dump(['backend' => 'api', 'api' => ['provider' => 'one', 'providers' => [
        'one' => ['url' => 'https://api.one.test/v1', 'model' => 'one/model', 'context' => 32768],
        'two' => ['url' => 'https://api.two.test/v1', 'key_env' => 'TWO_API_KEY', 'model' => 'two/model', 'context' => 8192],
    ]]], 4, 2));

    return $path;
}

/** @param array<int, array{url: string, auth: string}> $requests */
function keysSentTo(array $requests, string $host): array
{
    return array_values(array_unique(array_map(
        fn($r) => $r['auth'],
        array_filter($requests, fn($r) => str_contains($r['url'], $host)),
    )));
}

$list = provider(twoProviders($dir . '/list.yaml'), '');
check('/provider lists them, the one in force marked',
    str_contains($list['shown'] ?? '', '● one') && str_contains($list['shown'] ?? '', 'two/model'), json_encode($list));
check('and says which key variable is missing', str_contains($list['shown'] ?? '', 'TWO_API_KEY (not set)'), $list['shown'] ?? '');

$switched = provider(twoProviders($dir . '/switch.yaml'), 'two', "y\n", ['TWO_API_KEY' => 'k-two']);
check('/provider two moves the session there',
    ($switched['endpoint'] ?? null) === 'https://api.two.test/v1' && ($switched['model'] ?? null) === 'two/model', json_encode($switched));
check('with its own key, and only that one', keysSentTo($switched['requests'] ?? [], 'api.two.test') === ['Bearer k-two'],
    json_encode($switched['requests'] ?? []));
check('kept for the machine, it becomes the machine\'s provider',
    (Yaml::parseFile($dir . '/switch.yaml')['api']['provider'] ?? null) === 'two');

$notKept = provider(twoProviders($dir . '/session.yaml'), 'two', "n\n", ['TWO_API_KEY' => 'k-two']);
check('not kept, it holds for the session only',
    ($notKept['endpoint'] ?? null) === 'https://api.two.test/v1' && (Yaml::parseFile($dir . '/session.yaml')['api']['provider'] ?? null) === 'one',
    json_encode($notKept));

$noKey = provider(twoProviders($dir . '/nokey.yaml'), 'two', "y\n");
check('a provider whose variable is unset never gets SHERPA_API_KEY instead',
    keysSentTo($noKey['requests'] ?? [], 'api.two.test') === [''], json_encode($noKey['requests'] ?? []));
check('the refusal names the variable', str_contains($noKey['shown'] ?? '', 'TWO_API_KEY, not set'), $noKey['shown'] ?? '');
check('and the session stays where it was, with its key',
    ($noKey['endpoint'] ?? null) === 'https://api.one.test/v1' && ($noKey['model'] ?? null) === 'one/model', json_encode($noKey));

$unknown = provider(twoProviders($dir . '/unknown.yaml'), 'three');
check('an unknown name lists the known ones', str_contains($unknown['shown'] ?? '', 'Known: one, two'), $unknown['shown'] ?? '');

$same = provider(twoProviders($dir . '/same.yaml'), 'one');
check('the one in force says so', str_contains($same['shown'] ?? '', 'Already on one'), $same['shown'] ?? '');

$added = provider(twoProviders($dir . '/add.yaml'), 'add', "https://api.groq.com/openai/v1/chat/completions\n\nk-groq\n");
$groq = Yaml::parseFile($dir . '/add.yaml')['api']['providers']['groq'] ?? null;
check('/provider add records the address as pasted, cut back to its base, under the suggested name',
    ($groq['url'] ?? null) === 'https://api.groq.com/openai/v1', json_encode($added));
check('it tries the key before keeping it',
    str_contains($added['shown'] ?? '', 'accepts the key') && keysSentTo($added['requests'] ?? [], 'api.groq.com') === ['Bearer k-groq'],
    json_encode($added));
check('the key is not in config.yaml', !str_contains((string) file_get_contents($dir . '/add.yaml'), 'k-groq'));
check('but in keys.yaml, under the provider\'s name',
    (Yaml::parseFile($dir . '/keys.yaml')['groq'] ?? null) === 'k-groq');
check('which only its owner can read', (fileperms($dir . '/keys.yaml') & 0777) === 0600, decoct(fileperms($dir . '/keys.yaml') & 0777));
// Straight on to it: no model yet, so the list opens — which a pipe cannot
// answer, and the session stays where it was.
check('and it moves to it at once, which needs a model chosen',
    str_contains($added['shown'] ?? '', 'Still on') && ($added['endpoint'] ?? null) === 'https://api.one.test/v1', $added['shown'] ?? '');
@unlink($dir . '/keys.yaml');

$withModel = twoProviders($dir . '/addmodel.yaml');
$refused = provider($withModel, 'add', "https://api.three.test/v1\nthree\n\nc\n");
check('a key refused can be abandoned, and nothing is recorded',
    str_contains($refused['shown'] ?? '', 'refuses to go without one') && !isset(Yaml::parseFile($withModel)['api']['providers']['three']),
    $refused['shown'] ?? '');

// A key saved for a provider is used from then on, from any session.
file_put_contents($dir . '/keys.yaml', "two: k-saved\n");
$saved = provider(twoProviders($dir . '/saved.yaml'), 'two', "n\n");
check('a saved key is the one sent', keysSentTo($saved['requests'] ?? [], 'api.two.test') === ['Bearer k-saved']
    && ($saved['model'] ?? null) === 'two/model', json_encode($saved));
$fromVar = provider(twoProviders($dir . '/var.yaml'), 'two', "n\n", ['TWO_API_KEY' => 'k-var']);
check('the provider\'s own variable, set, wins over it', keysSentTo($fromVar['requests'] ?? [], 'api.two.test') === ['Bearer k-var']);

$rekeyed = provider(twoProviders($dir . '/rekey.yaml'), 'key two', "k-new\n");
check('/provider key replaces a key after trying it',
    str_contains($rekeyed['shown'] ?? '', 'Key of two saved') && (Yaml::parseFile($dir . '/keys.yaml')['two'] ?? null) === 'k-new',
    $rekeyed['shown'] ?? '');
file_put_contents($dir . '/keys.yaml', "two: ''\n");
$none = provider(twoProviders($dir . '/none.yaml'), 'two', "n\n");
check('a provider saved as wanting no key never gets SHERPA_API_KEY',
    keysSentTo($none['requests'] ?? [], 'api.two.test') === [''], json_encode($none['requests'] ?? []));
@unlink($dir . '/keys.yaml');

file_put_contents($dir . '/keys.yaml', "two: k-two\none: k-one\n");
$removed = provider(twoProviders($dir . '/remove.yaml'), 'remove two');
check('/provider remove forgets one', str_contains($removed['shown'] ?? '', 'Provider two forgotten')
    && !isset(Yaml::parseFile($dir . '/remove.yaml')['api']['providers']['two']), $removed['shown'] ?? '');
check('and its key with it', Yaml::parseFile($dir . '/keys.yaml') === ['one' => 'k-one']);
$refused = provider(twoProviders($dir . '/remove-active.yaml'), 'remove one');
check('but not the one in force', str_contains($refused['shown'] ?? '', 'is the provider in force'), $refused['shown'] ?? '');

exec('rm -rf ' . escapeshellarg($dir));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
