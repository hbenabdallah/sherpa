<?php
// The embedding model is found, never asked for: the user gives an address, a
// key and a chat model, and a chat model cannot make vectors. The provider's
// catalogue is read first; without one, known names are tried on one word. A
// model the provider does not have means "try the next"; a quota or an outage
// means "cannot tell today", which must not be recorded as "none".

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Backend;
use App\Config\MachineConfig;
use App\Platform\OllamaPlatform;
use App\Platform\OpenAiCompatiblePlatform;
use App\Rag\Embedding\EmbeddingDetector;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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

// ---- picking from a catalogue -------------------------------------------------
check('a known embedding model is picked out of a chat catalogue',
    EmbeddingDetector::pick(['gpt-4o', 'text-embedding-3-large', 'text-embedding-3-small']) === 'text-embedding-3-small');
check('an Ollama tag is matched without its ":latest"',
    EmbeddingDetector::pick(['qwen2.5-coder:7b', 'bge-m3:latest']) === 'bge-m3:latest');
check('an unknown model that says it embeds is still taken',
    EmbeddingDetector::pick(['gpt-4o', 'acme-embed-v2']) === 'acme-embed-v2');
check('a catalogue of chat models has none', EmbeddingDetector::pick(['gpt-4o', 'gpt-4o-mini']) === null);

/**
 * An OpenAI-compatible provider scripted by route: GET /models, then one answer
 * per POST /embeddings, in order. Records what was asked.
 *
 * @param list<MockResponse> $embeddings
 */
function provider(?MockResponse $models, array $embeddings, array &$asked): OpenAiCompatiblePlatform
{
    $client = new MockHttpClient(function (string $method, string $url, array $options) use ($models, &$embeddings, &$asked) {
        if (str_ends_with($url, '/models')) {
            $asked[] = 'GET /models';

            return $models ?? new MockResponse('', ['http_code' => 405]);
        }
        $asked[] = 'embed ' . (json_decode($options['body'] ?? '{}', true)['model'] ?? '?');

        return array_shift($embeddings) ?? new MockResponse('{"error":{"message":"no"}}', ['http_code' => 400]);
    });

    return new OpenAiCompatiblePlatform($client, 'https://api.example.com/v1', 'chat-model', 'key');
}

function vector(): MockResponse
{
    return new MockResponse('{"data":[{"index":0,"embedding":[0.1,0.2,0.3]}]}', ['http_code' => 200]);
}

function unknown(): MockResponse
{
    return new MockResponse('{"errors":[{"message":"AiError: No such model"}]}', ['http_code' => 400]);
}

// ---- OpenAI: the catalogue answers ------------------------------------------------
$asked = [];
$openai = provider(new MockResponse('{"data":[{"id":"gpt-4o"},{"id":"text-embedding-3-small"}]}', ['http_code' => 200]), [], $asked);
$found = (new EmbeddingDetector($openai))->detect(Backend::Api);
check('with a catalogue, the embedding model is read from it', $found->settled && $found->model === 'text-embedding-3-small', json_encode($found));
check('and nothing is sent to the chat model or embedded to find it', $asked === ['GET /models'], json_encode($asked));

// ---- Cloudflare: no catalogue, names are tried -------------------------------------------
$asked = [];
$cloudflare = provider(null, [unknown(), unknown(), vector()], $asked);
$found = (new EmbeddingDetector($cloudflare))->detect(Backend::Api);
check('without a catalogue, known names are tried until one embeds', $found->settled && $found->model === '@cf/baai/bge-m3', json_encode($found));
check('in order, and no further', $asked === ['GET /models', 'embed text-embedding-3-small', 'embed mistral-embed', 'embed @cf/baai/bge-m3'], json_encode($asked));

// ---- a provider with none (Anthropic, say) ---------------------------------------------------
$asked = [];
$found = (new EmbeddingDetector(provider(null, [], $asked)))->detect(Backend::Api);
check('a provider that has none is settled as none, keywords from then on', $found->settled && $found->model === null, json_encode($found));
check('after trying each known name once', count($asked) === 1 + count(EmbeddingDetector::PROBES), json_encode($asked));

// ---- a quota spent, a key refused: not a verdict -----------------------------------------------
$asked = [];
$found = (new EmbeddingDetector(provider(null, [new MockResponse('{"error":{"message":"bad key"}}', ['http_code' => 401])], $asked)))->detect(Backend::Api);
check('a refused key leaves the question open rather than recording "none"', !$found->settled && str_contains($found->reason, 'Key refused'), json_encode($found));
check('and stops trying at once', count($asked) === 2, json_encode($asked));

// ---- Ollama: what was pulled is all there is ---------------------------------------------------
$tags = static fn(string $json) => new OllamaPlatform(new MockHttpClient(new MockResponse($json, ['http_code' => 200])), 'http://ollama.test', 'qwen2.5-coder:7b');
$found = (new EmbeddingDetector($tags('{"models":[{"name":"qwen2.5-coder:7b"},{"name":"nomic-embed-text:latest"}]}')))->detect(Backend::Ollama);
check('a pulled embedding model is found in the tags', $found->model === 'nomic-embed-text:latest', json_encode($found));
$found = (new EmbeddingDetector($tags('{"models":[{"name":"qwen2.5-coder:7b"}]}')))->detect(Backend::Ollama);
check('none pulled is settled, with the command to pull one', $found->settled && $found->model === null && str_contains($found->reason, 'ollama pull'), json_encode($found));
$down = new OllamaPlatform(new MockHttpClient(new MockResponse('', ['http_code' => 503])), 'http://ollama.test', 'm');
$found = (new EmbeddingDetector($down))->detect(Backend::Ollama);
check('an Ollama that lists nothing is not a verdict', !$found->settled, json_encode($found));

// ---- remembered, and forgotten with the provider ---------------------------------------------
$root = sys_get_temp_dir() . '/sherpa-embed-' . bin2hex(random_bytes(4));
mkdir($root, 0777, true);
$config = new MachineConfig();
$config->setPath($root . '/config.yaml');

check('a fresh machine has settled nothing', !$config->hasEmbeddingChoice(Backend::Api));
$config->saveApiUrl('https://api.one.test/v1');
$config->saveEmbeddingModel(Backend::Api, '');
check('"none found" is remembered, so it is not asked again', $config->hasEmbeddingChoice(Backend::Api) && $config->embeddingModel(Backend::Api) === null);
$config->saveEmbeddingModel(Backend::Api, 'text-embedding-3-small');
$config->saveApiUrl('https://api.one.test/v1');
check('the same address keeps it', $config->embeddingModel(Backend::Api) === 'text-embedding-3-small');
$config->saveApiUrl('https://api.two.test/v1');
check('another provider forgets it, so it is detected again', !$config->hasEmbeddingChoice(Backend::Api));
check('and Ollama\'s is its own', !$config->hasEmbeddingChoice(Backend::Ollama));

exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
