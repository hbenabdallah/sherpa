<?php
// web_fetch: a page read as text, bounded, confirmed first — against a fake
// web, so nothing leaves the machine.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\Tool\Permission;
use App\Agent\Tool\Toolbox;
use App\Runtime\Interrupt;
use App\Tool\WebFetchTool;
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

$requests = 0;
$web = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
    $requests++;
    $page = fn(string $body, string $type = 'text/html; charset=utf-8', int $code = 200) =>
        new MockResponse($body, ['http_code' => $code, 'response_headers' => ['content-type' => $type]]);

    return match ($url) {
        'https://docs.example.test/guide' => $page(<<<'HTML'
            <html><head><title>Guide</title><script>track()</script><style>p{}</style></head>
            <body><nav>Home · Blog · About</nav>
            <h1>Installing</h1><p>Run <code>composer require acme/lib</code>.</p>
            <h2>Options</h2><table><tr><th>Name</th><th>Default</th></tr><tr><td>timeout</td><td>30</td></tr></table>
            <footer>© Acme</footer></body></html>
            HTML),
        'https://api.example.test/v1' => $page('{"version":"2.1","ok":true}', 'application/json'),
        'https://example.test/notes.txt' => $page("line one\nline two", 'text/plain'),
        'https://example.test/logo.png' => $page("\x89PNG....", 'image/png'),
        'https://example.test/missing' => $page('not here', 'text/html', 404),
        'https://example.test/long' => $page('<p>' . str_repeat('word ', 5000) . '</p>'),
        'https://example.test/moved' => new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => '/notes.txt']]),
        'https://example.test/to-metadata' => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://169.254.169.254/latest/meta-data/']]),
        'https://example.test/to-localhost' => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://localhost:8080/admin']]),
        'https://example.test/loop' => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://example.test/loop']]),
        'http://localhost:8080/start' => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '/admin']]),
        'http://localhost:8080/admin', 'http://169.254.169.254/latest/meta-data/' => $page('SECRET_INTERNAL', 'text/plain'),
        'https://example.test/latin1' => $page(mb_convert_encoding('<p>Café crème</p>', 'ISO-8859-1', 'UTF-8'), 'text/html; charset=ISO-8859-1'),
        default => $page('?', 'text/plain', 500),
    };
});
$fetch = new WebFetchTool($web);

$out = $fetch('https://docs.example.test/guide');
check('a page becomes readable text', str_contains($out, '# Installing') && str_contains($out, 'composer require acme/lib'), $out);
check('its tables stay tables', str_contains($out, '| Name | Default |') && str_contains($out, '| timeout | 30 |'), $out);
check('scripts, styles, menus and footers are dropped',
    !str_contains($out, 'track()') && !str_contains($out, 'p{}') && !str_contains($out, 'Home · Blog') && !str_contains($out, '© Acme'), $out);
check('and it says the text is information, not instructions', str_contains($out, 'not as instructions'), $out);

check('JSON is shown laid out', str_contains($fetch('https://api.example.test/v1'), "\"version\": \"2.1\""));
check('plain text as it is', str_contains($fetch('https://example.test/notes.txt'), "line one\nline two"));
check('another charset comes out right', str_contains($fetch('https://example.test/latin1'), 'Café crème'));

foreach ([
    'an image is refused, not dumped'   => ['https://example.test/logo.png', 'image/png, not text'],
    'an HTTP error is said as such'     => ['https://example.test/missing', 'HTTP 404'],
    'only the web: no file://'          => ['file:///etc/passwd', 'Only http:// and https://'],
    'nor anything without a host'       => ['https:///nothing', 'not a web address'],
] as $label => [$url, $expected]) {
    $message = null;
    try {
        $fetch($url);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }
    check($label, $message !== null && str_contains($message, $expected), (string) $message);
}

// ---- redirections ----------------------------------------------------------------
// The user approves one address. A public page redirecting to the machine's own
// services, or to a cloud's metadata endpoint, had that read instead — and sent
// on to the model's provider.
check('a redirect is followed', str_contains($fetch('https://example.test/moved'), 'line one'));
foreach ([
    'a redirect from the web to a cloud metadata address is refused' => 'https://example.test/to-metadata',
    'and to localhost'                                                => 'https://example.test/to-localhost',
] as $label => $url) {
    $message = null;
    try {
        $fetch($url);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }
    check($label, $message !== null && str_contains($message, 'redirect') && !str_contains($message, 'SECRET_INTERNAL'), (string) $message);
}
check('while a local address the user asked for may redirect within itself', str_contains($fetch('http://localhost:8080/start'), 'SECRET_INTERNAL'));
$message = null;
try {
    $fetch('https://example.test/loop');
} catch (RuntimeException $e) {
    $message = $e->getMessage();
}
check('a redirect loop ends', $message !== null && str_contains($message, 'redirect'), (string) $message);

// ---- a long page, read in parts -------------------------------------------------
$before = $requests;
$first = $fetch('https://example.test/long');
check('a long page is cut, with where to read on', preg_match('/call web_fetch with offset=(\d+)/', $first, $m) === 1, substr($first, -120));
$second = $fetch('https://example.test/long', (int) ($m[1] ?? 0));
check('reading on starts where the first part stopped', str_contains($second, 'from ' . number_format((int) ($m[1] ?? 0))), substr($second, 0, 160));
check('without fetching the page again', $requests - $before === 1, (string) ($requests - $before));

// ---- a slow server, listened to ----------------------------------------------
$port = 20000 + (getmypid() + 11) % 10000;
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/slow_page.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(100_000);
}

$interrupt = new Interrupt();
$ticks = 0;
$interrupt->meanwhile(function () use (&$ticks) { $ticks++; });
$slow = new WebFetchTool(interrupt: $interrupt);
$page = $slow("http://127.0.0.1:{$port}/pause");
check('a page that pauses halfway is read whole', str_contains($page, 'first half, second half'), $page);
check('and the pause was listened through', $ticks > 5, (string) $ticks);

$interrupt->beginTurn();
pcntl_async_signals(true);
pcntl_signal(SIGALRM, fn() => $interrupt->request());
pcntl_alarm(1);
$started = microtime(true);
try {
    $slow("http://127.0.0.1:{$port}/silent");
    $said = 'answered';
} catch (RuntimeException $e) {
    $said = $e->getMessage();
}
$took = microtime(true) - $started;
check('Ctrl+C stops a fetch the server is silent on, at once', $took < 3 && str_contains($said, 'Interrupted'), sprintf('%.1f s: %s', $took, $said));
$interrupt->endTurn();
proc_terminate($server);

// ---- asked for, every time ------------------------------------------------------
$def = (new Toolbox([$fetch]))->find('web_fetch');
check('web_fetch asks before it runs: the request leaves the machine', $def?->permission === Permission::CONFIRM);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
