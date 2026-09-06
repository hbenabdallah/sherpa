<?php
// What a provider says about its limits, read from its headers and shown at
// the bottom of the conversation — and nothing when it says nothing.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Platform\OpenAiCompatiblePlatform;
use App\Platform\RateLimits;
use App\Project\DockerConfig;
use App\Project\Project;
use App\TUI\StatusBar;
use App\TUI\Terminal;
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

// ---- the three families of headers -----------------------------------------
$openai = RateLimits::fromHeaders([
    'x-ratelimit-limit-requests'     => ['500'],
    'x-ratelimit-remaining-requests' => ['499'],
    'x-ratelimit-limit-tokens'       => ['30000'],
    'x-ratelimit-remaining-tokens'   => ['6000'],
    'x-ratelimit-reset-tokens'       => ['6m0s'],
]);
check('OpenAI-style headers are read, window by window',
    ($openai?->windows['requests']['remaining'] ?? null) === 499 && ($openai?->windows['tokens']['limit'] ?? null) === 30000,
    json_encode($openai?->windows));
check('the tightest window is the one shown', $openai?->tightest() === ['name' => 'tokens', 'percentLeft' => 20], json_encode($openai?->tightest()));
check('with its reset, as the provider wrote it', ($openai?->windows['tokens']['reset'] ?? null) === '6m0s');

$anthropic = RateLimits::fromHeaders([
    'anthropic-ratelimit-requests-limit'     => ['50'],
    'anthropic-ratelimit-requests-remaining' => ['49'],
    'anthropic-ratelimit-input-tokens-limit'     => ['40000'],
    'anthropic-ratelimit-input-tokens-remaining' => ['2000'],
]);
check("Anthropic's headers too", $anthropic?->tightest() === ['name' => 'input tokens', 'percentLeft' => 5], json_encode($anthropic?->windows));

$mistral = RateLimits::fromHeaders([
    'x-ratelimit-limit-tokens-month'     => ['1000000000'],
    'x-ratelimit-remaining-tokens-month' => ['870000000'],
    'x-ratelimit-limit-tokens-minute'    => ['500000'],
    'x-ratelimit-remaining-tokens-minute'=> ['499000'],
]);
check('a monthly quota is named as one', isset($mistral?->windows['tokens/month']) && $mistral?->tightest()['name'] === 'tokens/month',
    json_encode($mistral?->windows));

$ietf = RateLimits::fromHeaders(['RateLimit-Limit' => ['100, 100;w=60'], 'RateLimit-Remaining' => ['40']]);
check('the IETF draft form, header case and all', $ietf?->tightest() === ['name' => 'requests', 'percentLeft' => 40], json_encode($ietf?->windows));

check('a provider that sends none gets no figure', RateLimits::fromHeaders(['content-type' => ['text/event-stream']]) === null);
check('nor does a limit of zero, or a missing remainder',
    RateLimits::fromHeaders(['x-ratelimit-limit-tokens' => ['0'], 'x-ratelimit-remaining-tokens' => ['0'], 'x-ratelimit-limit-requests' => ['9']]) === null);

// ---- caught from a real reply -----------------------------------------------
$responses = [
    new MockResponse(['data: {"choices":[{"delta":{"content":"ok"}}]}' . "\n\ndata: [DONE]\n\n"], ['response_headers' => [
        'content-type' => 'text/event-stream',
        'x-ratelimit-limit-tokens' => '1000',
        'x-ratelimit-remaining-tokens' => '250',
    ]]),
    new MockResponse(['data: {"choices":[{"delta":{"content":"again"}}]}' . "\n\ndata: [DONE]\n\n"], ['response_headers' => ['content-type' => 'text/event-stream']]),
];
$platform = new OpenAiCompatiblePlatform(new MockHttpClient(fn() => array_shift($responses)), 'https://api.example.com/v1', 'm', 'k');
check('nothing before the first reply', $platform->rateLimits() === null);
$platform->stream([['role' => 'user', 'content' => 'x']], [], fn() => null);
check('the limits of the last reply are kept', $platform->rateLimits()?->tightest() === ['name' => 'tokens', 'percentLeft' => 25]);
$platform->stream([['role' => 'user', 'content' => 'x']], [], fn() => null);
check('a reply without headers does not erase what is known', $platform->rateLimits() !== null);
$platform->useEndpoint('https://other.example.com/v1');
check("and another provider starts from nothing: its limits are not the last one's", $platform->rateLimits() === null);

// ---- at the bottom of the conversation --------------------------------------
$project = new Project(slug: 'p', name: 'p', path: '/tmp', memoryDb: '/tmp/m.db', docker: new DockerConfig(enabled: false),
    stack: '', createdAt: new DateTimeImmutable(), lastUsedAt: new DateTimeImmutable());
$bar = (new ReflectionClass(StatusBar::class))->newInstanceWithoutConstructor();
$shown = function (?RateLimits $limits) use ($bar, $project): string {
    ob_start();
    $bar->render($project, 'm', 100, 1000, true, null, $limits);

    return ob_get_clean();
};
check('the tightest limit is in the status bar', str_contains(Terminal::plain($shown($openai)), 'tokens 20% left'), Terminal::plain($shown($openai)));
check('in amber when little is left', str_contains($shown($openai), Terminal::YELLOW . 'tokens 20% left'));
check('in red when almost nothing is', str_contains($shown($anthropic), Terminal::RED . 'input tokens 5% left'));
check('and not at all when the provider says nothing', !str_contains(Terminal::plain($shown(null)), 'left'), Terminal::plain($shown(null)));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
