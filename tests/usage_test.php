<?php
// What a session consumes, what it costs, and the cap that stops it — now that
// the default backend bills by the token.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Backend;
use App\Config\MachineConfig;
use App\Platform\OllamaPlatform;
use App\Platform\OpenAiCompatiblePlatform;
use App\Platform\SwitchablePlatform;
use App\Usage\Price;
use App\Usage\TokenCapReached;
use App\Usage\UsageMeter;
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
        echo '        ', substr($detail, 0, 300), "\n";
    }
}

function near(float $a, float $b): bool
{
    return abs($a - $b) < 1e-9;
}

// ---- a price ---------------------------------------------------------------
$price = new Price(input: 1.0, output: 4.0);
check('a million tokens in costs the input price', near($price->of(1_000_000, 0), 1.0));
check('and out, the output price', near($price->of(0, 1_000_000), 4.0));

$cachedPrice = new Price(input: 1.0, output: 4.0, cachedInput: 0.1);
check('cached prompt tokens are billed at their own rate',
    near($cachedPrice->of(1_000_000, 0, 400_000), 0.6 + 0.04), (string) $cachedPrice->of(1_000_000, 0, 400_000));
check('and at the input rate when the provider has none', near($price->of(1_000_000, 0, 400_000), 1.0));
check('a cached count larger than the prompt cannot make it negative', $cachedPrice->of(10, 0, 999) >= 0.0);

// ---- a session -------------------------------------------------------------
$meter = new UsageMeter();
check('nothing is shown before the first request', $meter->statusLabel() === null);

$meter->record(['prompt' => 1200, 'completion' => 300]);
$meter->record(['prompt' => 1500, 'completion' => 200, 'cached' => 1000]);
check('requests and tokens add up',
    $meter->requests() === 2 && $meter->prompt() === 2700 && $meter->completion() === 500 && $meter->total() === 3200,
    json_encode([$meter->requests(), $meter->prompt(), $meter->completion()]));
check('cache hits are kept apart', $meter->cached() === 1000);
check('with no price, no cost is claimed', $meter->cost() === null && $meter->costLabel() === null);
check('the status bar shows the tokens alone', $meter->statusLabel() === '3.2k tokens', (string) $meter->statusLabel());

// A price applies from the moment it is set: a session can change model, and
// each request keeps the price it was made at.
$meter->usePrice(new Price(input: 1.0, output: 4.0));
$meter->record(['prompt' => 1_000_000, 'completion' => 0]);
check('priced requests are costed', near($meter->cost() ?? 0.0, 1.0), (string) $meter->cost());
check('and the earlier ones are counted as unpriced rather than guessed', $meter->unpriced() === 2);
check('the summary says so',
    str_contains($meter->summary(), '2 unpriced left out') && str_contains($meter->summary(), '3 requests'), $meter->summary());

$priced = new UsageMeter();
$priced->usePrice(new Price(input: 0.5, output: 1.5, currency: '€'));
$priced->record(['prompt' => 10_000, 'completion' => 2_000]);
check('a small cost keeps its significant digits', $priced->costLabel() === '~0.0080 €', (string) $priced->costLabel());
check('and the status bar shows both', $priced->statusLabel() === '12k tokens · ~0.0080 €', (string) $priced->statusLabel());

// ---- the cap ---------------------------------------------------------------
$capped = new UsageMeter(cap: 10_000);
$capped->record(['prompt' => 7_000, 'completion' => 500]);
check('below 80 % of the cap, nothing is said', !$capped->shouldWarn());
$capped->record(['prompt' => 600, 'completion' => 100]);
check('past it, the user is told', $capped->shouldWarn());
check('once', !$capped->shouldWarn());

$refused = null;
try {
    $capped->assertRoom();
} catch (TokenCapReached $e) {
    $refused = $e;
}
check('below the cap a request goes through', $refused === null);

$capped->record(['prompt' => 2_000, 'completion' => 0]);
try {
    $capped->assertRoom();
} catch (TokenCapReached $e) {
    $refused = $e;
}
check('at the cap the next one is refused', $refused instanceof TokenCapReached);
check('and the refusal says what to do', str_contains($refused?->getMessage() ?? '', 'SHERPA_MAX_SESSION_TOKENS'), $refused?->getMessage() ?? '');
check('a request that goes straight past the cap is not also "warned" about', (function () {
    $jump = new UsageMeter(cap: 1_500);
    $jump->record(['prompt' => 1_800, 'completion' => 86]);

    return !$jump->shouldWarn();
})());
check('no cap means never refused', (function () {
    $free = new UsageMeter();
    $free->record(['prompt' => 50_000_000, 'completion' => 0]);
    $free->assertRoom();

    return !$free->shouldWarn();
})());

// ---- every request is counted, at the one door they all go through --------
$sse = fn(int $prompt, int $completion, int $cached = 0) => new MockResponse([
    'data: ' . json_encode(['choices' => [['delta' => ['content' => 'ok']]]]) . "\n\n",
    'data: ' . json_encode(['choices' => [], 'usage' => [
        'prompt_tokens' => $prompt,
        'completion_tokens' => $completion,
        'prompt_tokens_details' => ['cached_tokens' => $cached],
    ]]) . "\n\n",
    "data: [DONE]\n\n",
]);

$sent = 0;
$api = new OpenAiCompatiblePlatform(
    new MockHttpClient(function () use (&$sent, $sse) {
        $sent++;

        return $sse(900, 100, 600);
    }),
    'https://api.example.com/v1',
    'modele',
);
$ollama = new OllamaPlatform(new MockHttpClient(new MockResponse('{"message":{"content":"ok"},"done":true}')), 'http://x', 'm');

$sessionMeter = new UsageMeter(cap: 2_500);
$switch = new SwitchablePlatform($ollama, $api, $sessionMeter);
$switch->switchTo(Backend::Api);

$switch->stream([]);
check('a request through the switch is counted', $sessionMeter->requests() === 1 && $sessionMeter->total() === 1000,
    json_encode([$sessionMeter->requests(), $sessionMeter->total()]));
check('with its cache hits, read from the provider', $sessionMeter->cached() === 600, (string) $sessionMeter->cached());

$switch->stream([]);
$switch->stream([]);
$blocked = null;
try {
    $switch->stream([]);
} catch (TokenCapReached $e) {
    $blocked = $e;
}
check('the request that would exceed the cap is refused', $blocked instanceof TokenCapReached);
check('before anything is sent — nothing billed, nothing half-done', $sent === 3, (string) $sent);

// Zero cached tokens is not news, and not a new key in every usage array.
$plain = new OpenAiCompatiblePlatform(new MockHttpClient($sse(10, 2)), 'https://api.example.com/v1', 'm');
$plain->stream([]);
check('a usage with nothing cached keeps its two-key shape', $plain->lastUsage() === ['prompt' => 10, 'completion' => 2],
    json_encode($plain->lastUsage()));

// ---- prices are declared per model, by the user ----------------------------
$dir = sys_get_temp_dir() . '/sherpa-usage-' . bin2hex(random_bytes(4));
mkdir($dir, 0777, true);
file_put_contents($dir . '/config.yaml', <<<YAML
backend: api
api:
  model: 'fournisseur/modele'
  context: 32768
prices:
  'fournisseur/modele': { input: 0.15, output: 0.6, cached_input: 0.03, currency: '€' }
  'a-moitie': { input: 0.15 }
YAML);
$config = new MachineConfig();
$config->setPath($dir . '/config.yaml');

$declared = $config->price('fournisseur/modele');
check('a declared price is read',
    $declared !== null && near($declared->input, 0.15) && near($declared->output, 0.6)
    && near($declared->cachedInput ?? 0.0, 0.03) && $declared->currency === '€');
check('a model with no price has none', $config->price('autre') === null);
check('half a price is no price: a wrong number shown with confidence is worse',
    $config->price('a-moitie') === null);

exec('rm -rf ' . escapeshellarg($dir));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
