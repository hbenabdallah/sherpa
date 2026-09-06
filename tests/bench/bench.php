<?php
// Sherpa's benchmark: realistic tasks on throwaway projects, run end to end
// against a real model and scored automatically — how "good enough" gets a
// number. Not part of `make test`: it needs an API and costs a little. Run
// with `make bench`, optionally with
//
//     ARGS="--only=fix-bug,rename-class --repeat=3 --label=try"
//     ARGS="--list"                      # the runs already made
//     ARGS="--repeat=2 --compare=test-cmd"   # and the gap with one of them
//     ARGS="--compare=reference --to=list-dir"   # two runs already made
//     ARGS="--dry-run"                    # the scenarios with no agent: each must fail
//
// The model is SHERPA_BENCH_MODEL, or SHERPA_API_MODEL, or --model=.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Symfony\Component\Process\Process;

/**
 * One scenario, run once: a fresh copy of its project, a private HOME so
 * nothing touches the user's own configuration, and everything needed to
 * score what the agent did.
 */
final class BenchRun
{
    /** @var array<string, string> relative path => sha1, as the agent found the project */
    private array $baseline = [];

    /** @var array<string, mixed> */
    public array $transcript = [];
    public string $output = '';
    public float $seconds = 0.0;
    public ?int $exitCode = null;

    public function __construct(
        public readonly string $name,
        public readonly string $dir,
        public readonly string $home,
    ) {}

    public static function prepare(string $root, string $template, string $name, int $attempt): self
    {
        $base = "{$root}/{$name}-{$attempt}";
        $run = new self($name, "{$base}/project", "{$base}/home");

        (new Process(['mkdir', '-p', $base]))->mustRun();
        (new Process(['cp', '-a', $template, $run->dir]))->mustRun();
        mkdir($run->home . '/.config/sherpa', 0777, true);

        return $run;
    }

    /** Freeze what the project looks like before the agent sees it. */
    public function snapshot(): void
    {
        $this->baseline = $this->hashes();
    }

    public function configure(string $model): void
    {
        $now = date('c');
        $config = $this->home . '/.config/sherpa';

        file_put_contents("{$config}/config.yaml", Symfony\Component\Yaml\Yaml::dump([
            'backend' => 'api',
            'api'     => ['model' => $model, 'context' => 32768],
        ], 4, 2));

        // Standing grants, so writing and running commands never stop on a
        // confirmation nobody is there to give.
        file_put_contents("{$config}/projects.yaml", Symfony\Component\Yaml\Yaml::dump(['projects' => ['bench' => [
            'name'          => 'boutique',
            'path'          => $this->dir,
            'memory_db'     => "{$config}/projects/bench/memory.db",
            'docker'        => ['enabled' => false, 'container' => '', 'db_container' => ''],
            'stack'         => '',
            'created_at'    => $now,
            'last_used_at'  => $now,
            'allowed_tools' => ['file_write', 'file_patch', 'shell_exec'],
        ]]], 5, 2));
    }

    public function execute(string $prompt, int $timeout, int $tokenCap): void
    {
        $transcript = dirname($this->dir) . '/transcript.json';

        $process = new Process(
            ['php', 'bin/sherpa'],
            cwd: dirname(__DIR__, 2),
            env: [
                'HOME'                      => $this->home,
                'SHERPA_BACKEND'            => 'api',
                // The project is the directory Sherpa is launched from, as for
                // anyone using it; configure() registered this one, with its grants.
                'SHERPA_CWD'                => $this->dir,
                'SHERPA_TRANSCRIPT'         => $transcript,
                // A runaway loop costs this much at most.
                'SHERPA_MAX_SESSION_TOKENS' => (string) $tokenCap,
                // Scored on the task, not on the bookkeeping after it: the
                // extraction was one more request per run, ~7 % of a pass.
                'SHERPA_FACT_EXTRACTION'    => 'off',
            ],
            input: $prompt . "\n/exit\n",
            timeout: $timeout,
        );

        $started = microtime(true);
        try {
            $process->run();
            $this->exitCode = $process->getExitCode();
        } catch (Symfony\Component\Process\Exception\ProcessTimedOutException) {
            $this->exitCode = null;
        }
        $this->seconds = microtime(true) - $started;

        $this->output = (string) preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $process->getOutput() . $process->getErrorOutput());
        file_put_contents(dirname($this->dir) . '/output.txt', $this->output);

        $data = is_file($transcript) ? json_decode((string) file_get_contents($transcript), true) : null;
        $this->transcript = is_array($data) ? $data : [];
    }

    // ---- what the agent did ------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public function messages(): array
    {
        return $this->transcript['messages'] ?? [];
    }

    /** The last thing the agent said to the user. */
    public function answer(): string
    {
        foreach (array_reverse($this->messages()) as $message) {
            if (($message['role'] ?? '') === 'assistant' && empty($message['tool_calls'])) {
                return (string) ($message['content'] ?? '');
            }
        }

        return '';
    }

    /** @return array<int, array{name: string, arguments: array<string, mixed>}> */
    public function toolCalls(): array
    {
        $calls = [];
        foreach ($this->messages() as $message) {
            foreach ($message['tool_calls'] ?? [] as $call) {
                $calls[] = [
                    'name'      => (string) ($call['function']['name'] ?? '?'),
                    'arguments' => is_array($call['function']['arguments'] ?? null) ? $call['function']['arguments'] : [],
                ];
            }
        }

        return $calls;
    }

    /**
     * Calls that came back as errors, per tool — a failed call is a whole
     * turn spent learning nothing about the task.
     *
     * @return array<string, int>
     */
    public function toolErrors(): array
    {
        $names = [];
        $errors = [];

        foreach ($this->messages() as $message) {
            foreach ($message['tool_calls'] ?? [] as $call) {
                $names[$call['id'] ?? ''] = (string) ($call['function']['name'] ?? '?');
            }

            if (($message['role'] ?? '') === 'tool' && str_starts_with((string) ($message['content'] ?? ''), 'Error')) {
                $name = $names[$message['tool_call_id'] ?? ''] ?? (string) ($message['name'] ?? '?');
                $errors[$name] = ($errors[$name] ?? 0) + 1;
            }
        }

        return $errors;
    }

    public function calls(string $tool): int
    {
        return count(array_filter($this->toolCalls(), fn($c) => $c['name'] === $tool));
    }

    public function ranCommand(string $fragment): bool
    {
        foreach ($this->toolCalls() as $call) {
            if ($call['name'] === 'shell_exec' && str_contains((string) ($call['arguments']['command'] ?? ''), $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{requests: int, prompt: int, completion: int, cached: int, cost: ?float} */
    public function usage(): array
    {
        return ($this->transcript['usage'] ?? []) + ['requests' => 0, 'prompt' => 0, 'completion' => 0, 'cached' => 0, 'cost' => null];
    }

    /** The product's own search counters, from the run's project memory. */
    public function searchStats(): array
    {
        $db = $this->home . '/.config/sherpa/projects/bench/memory.db';
        if (!is_file($db)) {
            return [];
        }

        try {
            $pdo = new PDO('sqlite:' . $db);

            return array_map('intval', $pdo->query('SELECT key, value FROM project_stats')->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (Throwable) {
            return [];
        }
    }

    // ---- what happened to the project ---------------------------------------

    /** Files added, changed or removed since the snapshot. @return string[] */
    public function modified(): array
    {
        $now = $this->hashes();
        $changed = [];

        foreach ($now + $this->baseline as $path => $_) {
            if (($now[$path] ?? null) !== ($this->baseline[$path] ?? null)) {
                $changed[] = $path;
            }
        }

        sort($changed);

        return $changed;
    }

    public function exists(string $path): bool
    {
        return is_file($this->dir . '/' . $path);
    }

    public function read(string $path): string
    {
        return $this->exists($path) ? (string) file_get_contents($this->dir . '/' . $path) : '';
    }

    public function replaceIn(string $path, string $search, string $replace): void
    {
        $content = $this->read($path);
        if (!str_contains($content, $search)) {
            throw new RuntimeException("setup: “{$search}” not found in {$path}");
        }

        file_put_contents($this->dir . '/' . $path, str_replace($search, $replace, $content));
    }

    /** Lines of src/ and tests/ matching an extended regex. @return string[] */
    public function grep(string $pattern): array
    {
        $process = new Process(['grep', '-rnE', '--include=*.php', '-e', $pattern, 'src', 'tests'], $this->dir);
        $process->run();

        return array_values(array_filter(explode("\n", trim($process->getOutput()))));
    }

    public function testsPass(): bool
    {
        $process = new Process(['php', 'tests/run.php'], $this->dir, timeout: 60);
        $process->run();

        return $process->getExitCode() === 0;
    }

    /**
     * PHP files the agent touched that no longer parse.
     *
     * Checked on every scenario, whatever it asks: the first reference run
     * scored a rename as passed while it had left `use Boutique.User.…` in a
     * controller the tests never load.
     *
     * @return string[]
     */
    public function invalidPhp(): array
    {
        $invalid = [];
        foreach ($this->modified() as $path) {
            if (!str_ends_with($path, '.php') || !$this->exists($path)) {
                continue;
            }

            $lint = new Process(['php', '-l', $path], $this->dir, timeout: 30);
            $lint->run();
            if ($lint->getExitCode() !== 0) {
                $invalid[] = $path;
            }
        }

        return $invalid;
    }

    /** Run PHP against the project, autoloader loaded; true when it exits 0. */
    public function php(string $code): bool
    {
        $process = new Process(['php', '-r', "require 'autoload.php';\n" . $code], $this->dir, timeout: 30);
        $process->run();

        return $process->getExitCode() === 0;
    }

    /** @return array<string, string> */
    private function hashes(): array
    {
        $hashes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile()) {
                $hashes[substr($file->getPathname(), strlen($this->dir) + 1)] = sha1_file($file->getPathname());
            }
        }

        return $hashes;
    }
}

// ---- the run -----------------------------------------------------------------

$options = getopt('', ['only:', 'repeat:', 'label:', 'model:', 'timeout:', 'cap:', 'compare:', 'to:', 'list', 'dry-run']);
$only = isset($options['only']) ? explode(',', (string) $options['only']) : null;
$repeat = max(1, (int) ($options['repeat'] ?? 1));
$label = preg_replace('/[^a-z0-9_-]/i', '', (string) ($options['label'] ?? ''));
$timeout = (int) ($options['timeout'] ?? 600);
$cap = (int) ($options['cap'] ?? 200_000);
$model = (string) ($options['model'] ?? (getenv('SHERPA_BENCH_MODEL') ?: getenv('SHERPA_API_MODEL') ?: ''));

$repo = dirname(__DIR__, 2);

// --list answers from what is already on disk: no API, no model, no spending.
if (isset($options['list'])) {
    foreach (pastRuns($repo) as $id => $data) {
        $s = $data['summary'];
        printf(
            "%-28s %-26s %d/%d · %.1f req · %s tokens par tâche%s\n",
            $id,
            (string) ($data['model'] ?? '?'),
            $s['passed'], $s['total'],
            $s['total'] > 0 ? $s['requests'] / $s['total'] : 0,
            short($s['total'] > 0 ? $s['tokens'] / $s['total'] : 0),
            ($data['commit'] ?? '') === '' ? '' : ' · ' . $data['commit'],
        );
    }

    exit(0);
}

$previous = isset($options['compare']) ? findRun($repo, (string) $options['compare']) : null;

// Two passes already on disk, compared without running anything.
if ($previous !== null && isset($options['to'])) {
    $after = findRun($repo, (string) $options['to']);
    if ($after === null) {
        fwrite(STDERR, "No run matches \"{$options['to']}\".\n");
        exit(2);
    }

    printComparison($previous, $after['rows']);
    exit(0);
}
if (isset($options['compare']) && $previous === null) {
    fwrite(STDERR, "No run matches \"{$options['compare']}\" — --list shows the ones there are.\n");
    exit(2);
}

/**
 * Past runs, newest first, keyed by their id.
 *
 * @return array<string, array<string, mixed>>
 */
function pastRuns(string $repo): array
{
    $runs = [];

    foreach (glob("{$repo}/var/bench/*/results.json") ?: [] as $file) {
        $data = json_decode((string) file_get_contents($file), true);
        if (is_array($data) && isset($data['rows'])) {
            $runs[(string) ($data['run'] ?? basename(dirname($file)))] = $data;
        }
    }

    krsort($runs);

    return $runs;
}

/** The newest past run whose id contains $needle — a label is enough. */
function findRun(string $repo, string $needle): ?array
{
    foreach (pastRuns($repo) as $id => $data) {
        if (str_contains($id, $needle)) {
            return $data;
        }
    }

    return null;
}

/**
 * One line per scenario: how often it passed, and what it cost on average.
 * Runs are compared this way because they rarely hold the same number of
 * attempts — two of one scenario, one of another.
 *
 * @param array<int, array<string, mixed>> $rows
 *
 * @return array<string, array{runs: int, passed: int, requests: float, tokens: float}>
 */
function byScenario(array $rows): array
{
    $out = [];

    foreach ($rows as $row) {
        $name = (string) $row['scenario'];
        $out[$name] ??= ['runs' => 0, 'passed' => 0, 'requests' => 0.0, 'tokens' => 0.0];
        $out[$name]['runs']++;
        $out[$name]['passed'] += $row['passed'] ? 1 : 0;
        $out[$name]['requests'] += $row['requests'];
        $out[$name]['tokens'] += $row['tokens'];
    }

    foreach ($out as $name => $totals) {
        $out[$name]['requests'] = $totals['requests'] / max(1, $totals['runs']);
        $out[$name]['tokens'] = $totals['tokens'] / max(1, $totals['runs']);
    }

    ksort($out);

    return $out;
}

function short(float $tokens): string
{
    return $tokens >= 1000 ? number_format($tokens / 1000, 1, ',', ' ') . 'k' : number_format($tokens, 0, ',', ' ');
}

/**
 * What changed between two passes, scenario by scenario.
 *
 * Nothing here is a verdict: the same task has gone 34 requests one run and
 * 27 the next on identical code, so a difference of one is noise and only a
 * pass turning into a failure is a fact on its own.
 *
 * @param array<string, mixed>             $before
 * @param array<int, array<string, mixed>> $rows
 */
function printComparison(array $before, array $rows): void
{
    $old = byScenario($before['rows'] ?? []);
    $new = byScenario($rows);

    printf("
Gap with %s (%s)
", $before['run'] ?? '?', $before['model'] ?? '?');

    foreach (array_keys($new + $old) as $name) {
        $a = $old[$name] ?? null;
        $b = $new[$name] ?? null;

        if ($a === null || $b === null) {
            printf("  %-18s %s
", $name, $a === null ? 'nouveau' : 'absent de cette passe');
            continue;
        }

        printf(
            "  %-18s %d/%d → %d/%d · %.1f → %.1f req · %s → %s tokens
",
            $name,
            $a['passed'], $a['runs'], $b['passed'], $b['runs'],
            $a['requests'], $b['requests'],
            short($a['tokens']), short($b['tokens']),
        );
    }

    $rate = fn(array $s) => $s['total'] > 0 ? $s['passed'] / $s['total'] : 0.0;
    $avg = fn(array $s, string $key) => $s['total'] > 0 ? $s[$key] / $s['total'] : 0.0;
    $summary = [
        'passed'   => count(array_filter($rows, fn($r) => $r['passed'])),
        'total'    => count($rows),
        'requests' => array_sum(array_column($rows, 'requests')),
        'tokens'   => array_sum(array_column($rows, 'tokens')),
    ];
    $was = $before['summary'] ?? ['passed' => 0, 'total' => 0, 'requests' => 0, 'tokens' => 0];

    printf(
        "  %-18s %d %% → %d %% passed · %.1f → %.1f req · %s → %s tokens per task
",
        'ensemble',
        (int) round($rate($was) * 100), (int) round($rate($summary) * 100),
        $avg($was, 'requests'), $avg($summary, 'requests'),
        short($avg($was, 'tokens')), short($avg($summary, 'tokens')),
    );
}

$scenarios = require __DIR__ . '/scenarios.php';
if ($only !== null) {
    $scenarios = array_values(array_filter($scenarios, fn($s) => in_array($s['name'], $only, true)));
}

/**
 * Every scenario prepared and scored with no agent run at all.
 *
 * A scenario nobody has done anything to must fail: one that passes on an
 * untouched project measures nothing, and would go on reporting success
 * whatever the agent did. Needs no API, so it runs for free.
 */
if (isset($options['dry-run'])) {
    $root = "{$repo}/var/bench/" . date('Ymd-His') . '-a-blanc';
    $wrong = 0;

    foreach ($scenarios as $scenario) {
        $run = BenchRun::prepare($root, __DIR__ . '/projects/' . $scenario['project'], $scenario['name'], 1);
        if (isset($scenario['setup'])) {
            ($scenario['setup'])($run);
        }
        $run->snapshot();

        $held = [];
        foreach ($scenario['checks'] as $check => $holds) {
            try {
                if ($holds($run)) {
                    $held[] = $check;
                }
            } catch (Throwable $e) {
                $held[] = "{$check} (exception : {$e->getMessage()})";
            }
        }

        $measures = count($held) < count($scenario['checks']);
        $wrong += $measures ? 0 : 1;
        printf("  %-18s %s\n", $scenario['name'], $measures
            ? "\033[32mmeasures something\033[0m (" . (count($scenario['checks']) - count($held)) . ' check(s) fail with no agent)'
            : "\033[31mwon in advance\033[0m: " . implode(', ', $held));
    }

    (new Process(['rm', '-rf', $root]))->run();
    printf("\n%s\n", $wrong === 0 ? 'Every scenario measures something.' : "{$wrong} scenario(s) to look at.");

    exit($wrong === 0 ? 0 : 1);
}

// Everything above answers from disk; from here on it costs requests.
if ((getenv('SHERPA_API_URL') ?: '') === '' || $model === '') {
    fwrite(STDERR, "The benchmark talks to a real API: set SHERPA_API_URL, SHERPA_API_KEY\n"
        . "and the model (SHERPA_BENCH_MODEL, SHERPA_API_MODEL or --model=).\n");
    exit(2);
}

$runId = date('Ymd-His') . ($label !== '' ? "-{$label}" : '');
$root = "{$repo}/var/bench/{$runId}";

$commit = new Process(['git', '-c', 'safe.directory=*', 'rev-parse', '--short', 'HEAD'], $repo);
$commit->run();
$commit = trim($commit->getOutput()) . (trim((new Process(['git', '-c', 'safe.directory=*', 'status', '--porcelain'], $repo))->mustRun()->getOutput()) !== '' ? '+modifs' : '');

printf("Sherpa benchmark · %s · %d scenario(s) × %d · %s\n\n", $model, count($scenarios), $repeat, $commit);

$rows = [];

foreach ($scenarios as $scenario) {
    for ($attempt = 1; $attempt <= $repeat; $attempt++) {
        $run = BenchRun::prepare($root, __DIR__ . '/projects/' . $scenario['project'], $scenario['name'], $attempt);

        if (isset($scenario['setup'])) {
            ($scenario['setup'])($run);
        }
        $run->snapshot();
        $run->configure($model);

        printf("  %-18s #%d … ", $scenario['name'], $attempt);
        $run->execute($scenario['prompt'], $timeout, $cap);

        $failed = [];
        $checks = $scenario['checks'] + [
            'the PHP it changed is still valid' => fn(BenchRun $r) => $r->invalidPhp() === [],
        ];
        foreach ($checks as $check => $holds) {
            try {
                if (!$holds($run)) {
                    $failed[] = $check;
                }
            } catch (Throwable $e) {
                $failed[] = "{$check} ({$e->getMessage()})";
            }
        }

        if ($run->transcript === []) {
            $failed = ['no transcript: the session did not finish (see output.txt)'];
        }

        $usage = $run->usage();
        $stats = $run->searchStats();
        $row = [
            'scenario'  => $scenario['name'],
            'attempt'   => $attempt,
            'passed'    => $failed === [],
            'failed'    => $failed,
            'requests'  => $usage['requests'],
            'tokens'    => $usage['prompt'] + $usage['completion'],
            'cost'      => $usage['cost'],
            'seconds'   => round($run->seconds, 1),
            'tools'     => array_count_values(array_column($run->toolCalls(), 'name')),
            'errors'    => $run->toolErrors(),
            'groping'   => $stats['grep_groping'] ?? 0,
            'greps'     => ($stats['grep_hits'] ?? 0) + ($stats['grep_misses'] ?? 0),
            'grep_miss' => $stats['grep_misses'] ?? 0,
        ];
        $rows[] = $row;

        echo $row['passed'] ? "\033[32mpassed\033[0m" : "\033[31mfailed\033[0m";
        printf(" · %d req · %s tokens · %.0f s\n", $row['requests'], number_format($row['tokens'], 0, ',', ' '), $row['seconds']);
        foreach ($failed as $reason) {
            echo "      ✗ {$reason}\n";
        }
    }
}

// ---- the verdict ---------------------------------------------------------------

$n = count($rows);
$passed = count(array_filter($rows, fn($r) => $r['passed']));
$sum = fn(string $key) => array_sum(array_column($rows, $key));
$tools = [];
$errors = [];
foreach ($rows as $row) {
    foreach ($row['tools'] as $tool => $count) {
        $tools[$tool] = ($tools[$tool] ?? 0) + $count;
    }
    foreach ($row['errors'] as $tool => $count) {
        $errors[$tool] = ($errors[$tool] ?? 0) + $count;
    }
}
ksort($tools);
ksort($errors);

echo "\n";
printf("Passed              %d/%d (%d %%)\n", $passed, $n, $n > 0 ? (int) round($passed / $n * 100) : 0);
printf("Requests            %.1f on average\n", $n > 0 ? $sum('requests') / $n : 0);
printf("Tokens              %s au total, %s en moyenne\n",
    number_format($sum('tokens'), 0, ',', ' '), number_format($n > 0 ? $sum('tokens') / $n : 0, 0, ',', ' '));
printf("Searches            %d greps, %d with no result · %d groping turn(s)\n", $sum('greps'), $sum('grep_miss'), $sum('groping'));
printf("Tools               %s\n", implode(' · ', array_map(fn($t, $c) => "{$t} {$c}", array_keys($tools), $tools)));
printf("Failing calls       %s\n", $errors === [] ? 'none' : implode(' · ', array_map(
    fn($t, $c) => "{$t} {$c}/" . ($tools[$t] ?? 0),
    array_keys($errors),
    $errors,
)));
printf("Time                %.0f s\n", $sum('seconds'));
printf("Detail              %s\n", $root);

if ($previous !== null) {
    printComparison($previous, $rows);
}

file_put_contents("{$root}/results.json", json_encode([
    'run'     => $runId,
    'label'   => $label,
    'model'   => $model,
    'commit'  => $commit,
    'repeat'  => $repeat,
    'summary' => [
        'passed'   => $passed,
        'total'    => $n,
        'requests' => $sum('requests'),
        'tokens'   => $sum('tokens'),
        'greps'    => $sum('greps'),
        'groping'  => $sum('groping'),
        'tools'    => $tools,
        'errors'   => $errors,
        'seconds'  => $sum('seconds'),
    ],
    'rows'    => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
