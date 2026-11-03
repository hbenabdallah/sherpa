<?php

declare(strict_types=1);

namespace App\Tests\BackgroundFactsTest;

// The memory pass, read a stretch at a time in a process of its own. /exit
// used to wait for a whole-conversation request; these lock what replaced it:
// each pass reads only what the last one did not, even after compaction
// rewrote the history; the child writes its facts and vanishes without
// running the session's shutdown function (which hands back the tty and stops
// the MCP servers); and the session's own writes wait for the child's instead
// of failing on a locked database.

require __DIR__ . '/../vendor/autoload.php';

use App\Agent\MessageBag;
use App\Config\Backend;
use App\Memory\BackgroundFacts;
use App\Memory\FactCursor;
use App\Memory\FactExtractor;
use App\Memory\MemoryStore;
use App\Memory\Recorded;
use App\Platform\OllamaPlatform;
use App\Platform\OpenAiCompatiblePlatform;
use App\Platform\SwitchablePlatform;
use App\Runtime\Interrupt;
use App\Usage\UsageMeter;
use Doctrine\DBAL\Exception as DatabaseError;
use PDO;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo "        ", substr($detail, 0, 300), "\n";
    }
}

function conversation(int $exchanges): MessageBag
{
    $bag = new MessageBag();
    $bag->system('SYSTEM');
    for ($i = 0; $i < $exchanges; $i++) {
        $bag->user("question {$i}");
        $bag->assistant("reply {$i}");
    }

    return $bag;
}

/** An extractor whose model answers $reply, and whose requests land in $seen. */
function extractor(MemoryStore $store, string $reply, string &$seen = ''): FactExtractor
{
    $client = new MockHttpClient(function ($method, $url, array $options) use (&$seen, $reply): MockResponse {
        $seen = $options['body'] ?? '';

        return new MockResponse(json_encode(['message' => ['content' => $reply], 'done' => true]));
    });

    return new FactExtractor(new OllamaPlatform($client, 'http://x', 'm', 32768, -1.0, new Interrupt()), $store, new Interrupt());
}

function database(): string
{
    return sys_get_temp_dir() . '/sherpa-bgfacts-' . bin2hex(random_bytes(4)) . '/memory.db';
}

// ---- the cursor --------------------------------------------------------------

$bag = conversation(2);
$cursor = new FactCursor();
check('a cursor that has read nothing starts at the top', $cursor->from($bag) === 0);

$cursor->advance($bag);
check('once advanced, nothing is left to read', $cursor->from($bag) === $bag->count());

$bag->user('question 2');
$bag->assistant('reply 2');
check('what came after is what is left', $cursor->from($bag) === $bag->count() - 2);

// Compaction folds the oldest turns into one summary: the place of the last
// message read moves down, and the cursor follows it there.
$messages = $bag->all();
$bag->replace([$messages[0], ['role' => 'user', 'content' => '[summary of the earlier turns]'], ...array_slice($messages, 3)]);
check(
    'after compaction it still starts right after the last message read',
    $cursor->from($bag) === $bag->count() - 2,
    "from {$cursor->from($bag)} of {$bag->count()}",
);

// An assistant message that only calls tools has no words to be found by.
$bag = conversation(1);
$bag->assistant('', [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'file_read', 'arguments' => '{}']]]);
$bag->tool('file_read', 'contents', 'c1');
$cursor = new FactCursor();
$cursor->advance($bag);
check('a tool call with no words is no anchor: the last message with words is', $cursor->from($bag) === 3, "from {$cursor->from($bag)}");

// /reset or /resume: the history is another one, and the anchor is in none of it.
$cursor = new FactCursor();
$cursor->advance(conversation(3));
$other = new MessageBag();
$other->system('SYSTEM');
$other->user('a whole other conversation');
check('a history without the anchor is read from the top', $cursor->from($other) === 0);

// ---- reading from a message on ----------------------------------------------

$store = new MemoryStore();
$store->open(database());
$seen = '';
$bag = conversation(3);
extractor($store, '[]', $seen)->extract($bag, 5);
check('a pass from a message on hands over only what follows', str_contains($seen, 'question 2') && !str_contains($seen, 'question 1'), substr($seen, -300));

$facts = extractor($store, '[]');
check('nothing after the cursor is nothing pending', !$facts->hasPending($bag, $bag->count()));
check('a greeting is nothing pending either', !$facts->hasPending(conversation(1), 0));
check('a conversation not read yet is pending', $facts->hasPending($bag, 0));

// Seen live: a long paste in the first question crossed the threshold, the
// pass skipped the session as too short, and the cursor moved past the paste.
$first = new MessageBag();
$first->system('SYSTEM');
$first->user(str_repeat('a long first question ', 1000));
$first->assistant('ok');
check(
    'a long first exchange is not worth a pass until the session counts as one',
    !$facts->hasPending($first, 0, atLeast: 12000),
);
$first->user('and a second question');
$first->assistant('ok');
check('it is once the session does', $facts->hasPending($first, 0, atLeast: 12000));
check('and not when what is left is shorter than asked', !$facts->hasPending($first, 3, atLeast: 12000));

// ---- the pass in a process of its own -----------------------------------------

/** A model answering $reply, as the Ollama backend does, with what it counted. */
function ollamaReplying(string $reply, int $prompt = 1000, int $completion = 20): MockHttpClient
{
    $body = ['message' => ['content' => $reply], 'done' => true, 'prompt_eval_count' => $prompt, 'eval_count' => $completion];

    return new MockHttpClient(static fn(): MockResponse => new MockResponse(json_encode($body, JSON_THROW_ON_ERROR)));
}

/**
 * A background pass whose session talks through one client and whose child
 * through another, so a check can tell which the child asked through.
 */
function background(MemoryStore $store, HttpClientInterface $sessionClient, HttpClientInterface $childClient, UsageMeter $usage = new UsageMeter()): BackgroundFacts
{
    $ollama = new OllamaPlatform($sessionClient, 'http://x', 'm', 32768, -1.0, new Interrupt());
    $platform = new SwitchablePlatform($ollama, new OpenAiCompatiblePlatform(new MockHttpClient(), 'http://y', 'm'), $usage);
    $platform->switchTo(Backend::Ollama);

    return new BackgroundFacts(new FactExtractor($platform, $store, new Interrupt()), $store, $platform, $childClient, new Interrupt(), $usage);
}

function waitFor(BackgroundFacts $background): void
{
    $deadline = microtime(true) + 10;
    while ($background->isRunning() && microtime(true) < $deadline) {
        usleep(20_000);
    }
}

$unsaved = new MemoryStore();
$unsaved->open(':memory:');
$background = background($unsaved, ollamaReplying('[]'), ollamaReplying('[]'));
check('a project not saved yet has no file a child could write to: no background pass', !$background->start(conversation(2), 0));

// The session's shutdown function must not run in the child: it would hand the
// tty back and stop the MCP servers under the session still using them.
$marker = sys_get_temp_dir() . '/sherpa-bgfacts-shutdown-' . bin2hex(random_bytes(4));
$parent = getmypid();
register_shutdown_function(static function () use ($marker, $parent): void {
    if (getmypid() !== $parent) {
        touch($marker);
    }
});

$path = database();
$store = new MemoryStore();
$store->open($path);
$background = background(
    $store,
    ollamaReplying('[{"key":"from_the_session_client","value":"the child asked through the wrong client"}]'),
    ollamaReplying('[{"key":"test_runner","value":"make test, in Docker"}]'),
);
check('a saved project gets a background pass', $background->start(conversation(2), 0));
waitFor($background);
check('the pass ends on its own', !$background->isRunning());

$reader = new MemoryStore();
$reader->open($path);
$found = $reader->recall('test_runner');
check('what the child found is in the project\'s memory', count($found) === 1, json_encode($found));

// Seen live: a child reusing the session's pooled connection had it cut by the
// session's next request, "SSL_read: unexpected eof", and kept nothing.
check(
    'the child asked through a client of its own, not the session\'s',
    $reader->recall('from_the_session_client') === [],
    json_encode($reader->recall()),
);
check('and the child left without running the session\'s shutdown function', !file_exists($marker));

// Seen while building it: with 0, 1 and 2 closed, the first warning the child
// displays fails to write, and PHP ends the script through its shutdown
// functions, past the finally that was to end it quietly.
$warning = new MockHttpClient(static function (): MockResponse {
    trigger_error('a library warns, as one sometimes does', E_USER_WARNING);

    return new MockResponse(json_encode(['message' => ['content' => '[]'], 'done' => true]));
});
$store = new MemoryStore();
$store->open(database());
$background = background($store, ollamaReplying('[]'), $warning);
$background->start(conversation(2), 0);
waitFor($background);
check('a warning in the child does not run the session\'s shutdown function either', !file_exists($marker));

// ---- what a pass spent counts in the session's usage ---------------------------
// Seen live: the summary at /exit said nothing of the passes, and the token cap
// never saw them, since they ran in another process.

$store = new MemoryStore();
$store->open(database());
$usage = new UsageMeter();
$background = background($store, ollamaReplying('[]'), ollamaReplying('[]', prompt: 3000, completion: 40), $usage);
$background->start(conversation(2), 0);
$background->start(conversation(3), 0);
waitFor($background);
check(
    'two passes under way at once are both reaped, and both counted',
    $usage->requests() === 2 && $usage->prompt() === 6000 && $usage->completion() === 80,
    "{$usage->requests()} requests, {$usage->prompt()} + {$usage->completion()} tokens",
);

$usage = new UsageMeter();
$background = background($store, ollamaReplying('[]'), ollamaReplying('[]'), $usage);
$background->start(conversation(1), 0);
waitFor($background);
check('a pass that asked the model nothing counts nothing', $usage->requests() === 0, "{$usage->requests()} requests");

// ---- two writers at once ------------------------------------------------------
// The child writes while the session does (memory_remember). SQLite fails a
// second writer at once unless told to wait; PDO tells it to, for up to a
// minute, and this design counts on that.

$path = database();
$store = new MemoryStore();
$store->open($path);

$holder = pcntl_fork();
if ($holder === 0) {
    $lock = new PDO('sqlite:' . $path);
    $lock->exec('BEGIN IMMEDIATE');
    usleep(600_000);
    $lock->exec('COMMIT');
    posix_kill(posix_getpid(), SIGKILL);
}

usleep(200_000);
$recorded = null;
$error = '';

try {
    $recorded = $store->remember('written_meanwhile', 'waited for the other writer');
} catch (DatabaseError $e) {
    $error = $e->getMessage();
}
pcntl_waitpid($holder, $status);
check('a write while another process writes waits for it rather than failing', $recorded === Recorded::Created, $error);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
