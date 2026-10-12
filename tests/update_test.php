<?php
// A new release, told and installed. Before, a release reached nobody who did
// not go and look, and updating meant finding install.sh again. Every request
// to GitHub here is a fake; the executable replaced is a file of this test's.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Command\UpdateCommand;
use App\Kernel;
use App\Update\Releases;
use App\Update\SelfUpdater;
use App\Update\UpdateFailed;
use App\Update\UpdateNotice;
use Symfony\Component\Console\Tester\CommandTester;
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

$root = sys_get_temp_dir() . '/sherpa-update-' . bin2hex(random_bytes(4));
mkdir($root, 0777, true);

/** GitHub as install.sh sees it: /releases/latest redirects to the tag. */
function latestIs(?string $tag, int &$asked = 0): Releases
{
    return new Releases(new MockHttpClient(function (string $method, string $url) use ($tag, &$asked) {
        $asked++;

        return $tag === null
            ? new MockResponse('', ['error' => 'Could not resolve host: github.com'])
            : new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: ' . Releases::URL . '/tag/' . $tag]]);
    }));
}

// ---- which is the latest ------------------------------------------------------
check('the latest release is read from the redirect to its tag', latestIs('v1.0.2')->latest(1.0) === '1.0.2');
check('offline, there is no latest rather than an error', latestIs(null)->latest(1.0) === null);
check('a tag that is not a release number is not one', latestIs('nightly')->latest(1.0) === null);

check('Linux x86_64 is linux-x86_64', Releases::platform('Linux', 'x86_64') === 'linux-x86_64');
check('Linux on ARM is linux-aarch64', Releases::platform('Linux', 'aarch64') === 'linux-aarch64');
check('an Apple Silicon Mac is macos-aarch64', Releases::platform('Darwin', 'arm64') === 'macos-aarch64');
check('an Intel Mac is macos-x86_64', Releases::platform('Darwin', 'x86_64') === 'macos-x86_64');
check('Windows has no executable', Releases::platform('Windows', 'x86_64') === null);

// ---- the notice at start-up ---------------------------------------------------
$now = 1_800_000_000;
$asked = 0;
$notice = new UpdateNotice(latestIs('v1.0.2', $asked), $root . '/cache/latest-release.json', '1.0.1');
$said = $notice->message($now);
check('a newer release is announced, with how to get it', $said === 'Sherpa 1.0.2 is available (you have 1.0.1): sherpa update', (string) $said);
check('and the answer is kept in the cache', is_file($root . '/cache/latest-release.json'));

$notice->message($now + 3_600);
check('GitHub is asked at most once a day', $asked === 1, (string) $asked);
$notice->message($now + 90_000);
check('and again the next day', $asked === 2, (string) $asked);

check('nothing is said when this is the latest',
    (new UpdateNotice(latestIs('v1.0.1'), $root . '/same.json', '1.0.1'))->message($now) === null);
check('nor when this is newer than the latest, a release not yet tagged',
    (new UpdateNotice(latestIs('v1.0.1'), $root . '/ahead.json', '1.0.2'))->message($now) === null);

$asked = 0;
$checkout = new UpdateNotice(latestIs('v9.9.9', $asked), $root . '/dev.json', 'dev (v1.0.1-3-gabc)');
check('a checkout is not told, nor is GitHub asked: it updates with git', $checkout->message($now) === null && $asked === 0);

$asked = 0;
$offline = new UpdateNotice(latestIs(null, $asked), $root . '/offline.json', '1.0.1');
$offline->message($now);
$offline->message($now + 60);
check('offline, it says nothing, and does not wait on GitHub at every start', $offline->message($now + 120) === null && $asked === 1, (string) $asked);

putenv('SHERPA_UPDATE_CHECK=off');
$asked = 0;
check('SHERPA_UPDATE_CHECK=off turns it off, request included',
    (new UpdateNotice(latestIs('v1.0.2', $asked), $root . '/off.json', '1.0.1'))->message($now) === null && $asked === 0);
putenv('SHERPA_UPDATE_CHECK');

// ---- installing ---------------------------------------------------------------
$binary = "#!/bin/sh\necho 'Sherpa 1.0.2'\n";
$sum = hash('sha256', $binary) . "  sherpa-1.0.2-linux-x86_64\n";

/** The release's files as GitHub serves them, by URL. */
function serving(array $files, array &$requested = []): MockHttpClient
{
    return new MockHttpClient(function (string $method, string $url) use ($files, &$requested) {
        $requested[] = $url;

        return isset($files[$url]) ? new MockResponse($files[$url]) : new MockResponse('Not Found', ['http_code' => 404]);
    });
}

$base = Releases::URL . '/download/v1.0.2/sherpa-1.0.2-linux-x86_64';
$target = $root . '/bin/sherpa';
mkdir(dirname($target));
file_put_contents($target, "old sherpa\n");
chmod($target, 0755);

$requested = [];
$client = serving([$base => $binary, $base . '.sha256' => $sum], $requested);
(new SelfUpdater($client, new Releases($client)))->install('1.0.2', 'linux-x86_64', $target);
clearstatcache();
check('the release takes the executable\'s place', file_get_contents($target) === $binary);
check('executable', (fileperms($target) & 0777) === 0755, decoct(fileperms($target) & 0777));
check('fetched from the release, checksum beside it, then its notes',
    $requested === [$base, $base . '.sha256', Releases::URL . '/download/v1.0.2/release-notes.md'], json_encode($requested));
check('and nothing is left beside it', glob(dirname($target) . '/.sherpa-update-*') === []);

/** The reason an install failed, or null when it did not. */
function refused(MockHttpClient $client, string $target): ?string
{
    try {
        (new SelfUpdater($client, new Releases($client)))->install('1.0.2', 'linux-x86_64', $target);

        return null;
    } catch (UpdateFailed $e) {
        return $e->getMessage();
    }
}

file_put_contents($target, "old sherpa\n");
$tampered = refused(serving([$base => $binary . 'tampered', $base . '.sha256' => $sum]), $target);
check('a download that does not match the release\'s SHA-256 is refused', str_contains((string) $tampered, 'SHA-256'), (string) $tampered);
check('and the executable is left as it was', file_get_contents($target) === "old sherpa\n");

$unsummed = refused(serving([$base => $binary]), $target);
check('so is one whose checksum is missing', str_contains((string) $unsummed, 'checksum') && file_get_contents($target) === "old sherpa\n", (string) $unsummed);

$absent = refused(serving([]), $target);
check('a release with no executable for this machine says so', str_contains((string) $absent, 'no Sherpa 1.0.2 for linux-x86_64'), (string) $absent);

mkdir($root . '/locked');
file_put_contents($root . '/locked/sherpa', "old\n");
chmod($root . '/locked', 0555);
$locked = refused(serving([$base => $binary, $base . '.sha256' => $sum]), $root . '/locked/sherpa');
chmod($root . '/locked', 0755);
check('a directory it cannot write in is said before anything is downloaded',
    posix_getuid() === 0 || str_contains((string) $locked, 'cannot write in'), (string) $locked);

// ---- sherpa update ------------------------------------------------------------
function update(array $args, ?string $executable, string $current, MockHttpClient $client): CommandTester
{
    $releases = new Releases($client);
    $tester = new CommandTester(new UpdateCommand($releases, new SelfUpdater($client, $releases), $executable, $current, 'linux-x86_64'));
    $tester->execute($args);

    return $tester;
}

$latest = Releases::URL . '/latest';
$notesUrl = Releases::URL . '/download/v1.0.2/release-notes.md';
$github = fn(?string $notes = null) => new MockHttpClient(function (string $method, string $url) use ($latest, $base, $binary, $sum, $notesUrl, $notes) {
    return match ($url) {
        $latest          => new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: ' . Releases::URL . '/tag/v1.0.2']]),
        $base            => new MockResponse($binary),
        $base . '.sha256' => new MockResponse($sum),
        $notesUrl        => $notes === null ? new MockResponse('', ['http_code' => 404]) : new MockResponse($notes),
        default          => new MockResponse('', ['http_code' => 404]),
    };
});

file_put_contents($target, "old sherpa\n");
$run = update([], $target, '1.0.1', $github());
check('sherpa update installs the latest release', $run->getStatusCode() === 0 && file_get_contents($target) === $binary, $run->getDisplay());
check('and says what it replaced', str_contains($run->getDisplay(), 'Sherpa 1.0.2 installed') && str_contains($run->getDisplay(), 'was 1.0.1'), $run->getDisplay());

// What changed, shown once installed. From the network: shown as text.
file_put_contents($target, "old sherpa\n");
$run = update([], $target, '1.0.1', $github("## What changed in Sherpa 1.0.2\n\n- Faster \e[2Jstartup\n- <error>tag</error> kept as text\n"));
check('once installed, what the release changed is shown', str_contains($run->getDisplay(), 'What changed in Sherpa 1.0.2') && str_contains($run->getDisplay(), '- Faster'), $run->getDisplay());
check('as text: no escape sequence, no console tag of its own', !str_contains($run->getDisplay(), "\e[2J") && str_contains($run->getDisplay(), '<error>tag</error>'), $run->getDisplay());
file_put_contents($target, "old sherpa\n");
$run = update([], $target, '1.0.1', $github(implode("\n", array_map(fn($i) => "- change {$i}", range(1, 100)))));
check('a screenful at most, with where the rest is', !str_contains($run->getDisplay(), 'change 41') && str_contains($run->getDisplay(), 'the rest at'), $run->getDisplay());
file_put_contents($target, "old sherpa\n");
$run = update([], $target, '1.0.1', $github());
check('a release without notes installs all the same', $run->getStatusCode() === 0 && file_get_contents($target) === $binary, $run->getDisplay());

$run = update([], $target, '1.0.2', $github());
check('on the latest already, it says so and downloads nothing', $run->getStatusCode() === 0 && str_contains($run->getDisplay(), 'is the latest'), $run->getDisplay());

file_put_contents($target, "old sherpa\n");
$run = update(['version' => 'v1.0.2'], $target, '1.0.3', $github());
check('a version named is installed, going back included', $run->getStatusCode() === 0 && file_get_contents($target) === $binary, $run->getDisplay());

$run = update(['version' => '1.0.2; rm -rf ~'], $target, '1.0.1', $github());
check('a version that is not a release number is refused', $run->getStatusCode() === 1 && str_contains($run->getDisplay(), 'Not a release number'), $run->getDisplay());

$run = update([], null, '1.0.1', $github());
check('from the source, it points at git instead', $run->getStatusCode() === 1 && str_contains($run->getDisplay(), 'git pull'), $run->getDisplay());

$run = update([], $target, '1.0.1', new MockHttpClient(new MockResponse('', ['error' => 'offline'])));
check('with GitHub out of reach, it says so and suggests naming a version', $run->getStatusCode() === 1 && str_contains($run->getDisplay(), 'sherpa update 1.0.1'), $run->getDisplay());

// ---- the notes, from the history -----------------------------------------------
// Run on this repository's own tags: v1.0.1 is the commits after v1.0.0.
$repo = dirname(__DIR__);
if (is_dir($repo . '/.git') && trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' tag -l v1.0.1 2>/dev/null')) === 'v1.0.1') {
    $notes = (string) shell_exec('cd ' . escapeshellarg($repo) . ' && sh bin/release-notes.sh v1.0.1 2>&1');
    check('the notes of a release are its commits since the one before',
        str_contains($notes, 'What changed in Sherpa 1.0.1') && str_contains($notes, '- Say at start-up when a newer release is out')
        && str_contains($notes, 'Since 1.0.0.'), $notes);
    check('and none from the release before', !str_contains($notes, 'Confine symlinks'), $notes);
} else {
    check('the notes script (skipped: no git history with tags here)', true);
}

// ---- wired --------------------------------------------------------------------
$kernel = new Kernel('dev', true);
$kernel->boot();
$loader = $kernel->getContainer()->get('console.command_loader');
$command = $loader->has('update') ? $loader->get('update') : null;
$command = $command instanceof Symfony\Component\Console\Command\LazyCommand ? $command->getCommand() : $command;
check('sherpa update is a command of the application, its parts wired', $command instanceof UpdateCommand);
$session = $loader->get('sherpa');
$session = $session instanceof Symfony\Component\Console\Command\LazyCommand ? $session->getCommand() : $session;
check('and the session is handed the notice', (new ReflectionProperty($session, 'updates'))->getValue($session) instanceof UpdateNotice);
foreach (['bin/sherpa', 'bin/sherpa-release.php'] as $entry) {
    check("and {$entry} does not read it as the session's options",
        str_contains((string) file_get_contents(dirname(__DIR__) . '/' . $entry), "['mcp', 'update']"));
}

exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
