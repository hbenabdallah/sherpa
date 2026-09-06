<?php
// The documentation search measured where it counts: in the agent's answers.
//
// tests/rag/eval_live.php measures whether the right passage is found. This
// runs the whole agent — the real command, a real model — on questions about
// a project's documentation, and looks at what it did:
//
//   - did it search the documentation (doc_search), rather than answer from memory?
//   - did its searches bring back the section that answers?
//   - does its answer cite that source — the file, and the section?
//   - on a question the documentation does not answer ("expect": []), did it say so?
//
// Whether an answer is right is not scored: that takes a reader. Every answer
// is kept, beside its question and the section expected, for one.
//
// Not part of `make test`: a real model, a few requests per question.
//
//   SHERPA_API_URL, SHERPA_API_KEY   the provider, as for the chat
//   SHERPA_API_MODEL                 the chat model, unless --model= is given
//   SHERPA_EMBEDDING_MODEL           the embedding model; without one, keywords only
//
//   php tests/rag/answer_eval.php <project-dir> [<questions.json>] [--model=m] [--only=1,4,9]
//       [--repeat=3] [--label=avant]
//
// --repeat asks each question several times: the same model on the same
// question does not do the same thing twice, and one run of a change proves
// little. --label names the run's directory, to tell two runs apart.
//
// The questions default to <project-dir>/.sherpa/rag-questions.json. The agent
// works on a copy of the project, so nothing it does touches the original.
// Answers and transcripts go to var/rag-answers/<date>/.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Platform\OpenAiCompatiblePlatform;
use App\Rag\Chunker;
use App\Rag\DocIndex;
use App\Rag\DocSource;
use App\Rag\Embedding\ChunkEmbedder;
use App\Rag\Embedding\FixedEmbeddings;
use App\Rag\Ingestor;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

$options = [];
$positional = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2];
    } else {
        $positional[] = $arg;
    }
}

$source = realpath($positional[0] ?? '') ?: '';
$url = getenv('SHERPA_API_URL') ?: '';
$model = $options['model'] ?? (getenv('SHERPA_API_MODEL') ?: '');
$embeddingModel = getenv('SHERPA_EMBEDDING_MODEL') ?: '';

if ($source === '' || !is_dir($source) || $url === '' || $model === '') {
    fwrite(STDERR, "Usage : php tests/rag/answer_eval.php <projet> [<questions.json>] [--model=m] [--only=1,4,9] [--repeat=3] [--label=x]\n"
        . "avec SHERPA_API_URL, SHERPA_API_MODEL (ou --model=), et SHERPA_API_KEY / SHERPA_EMBEDDING_MODEL au besoin.\n");
    exit(2);
}

$questionFile = $positional[1] ?? $source . '/.sherpa/rag-questions.json';
$questions = json_decode((string) @file_get_contents($questionFile), true);
if (!is_array($questions) || $questions === []) {
    fwrite(STDERR, "Aucune question lisible dans {$questionFile}\n");
    exit(2);
}

$only = isset($options['only']) ? array_map('intval', explode(',', $options['only'])) : null;
$timeout = (int) ($options['timeout'] ?? 300);
$repeat = max(1, (int) ($options['repeat'] ?? 1));

$repo = dirname(__DIR__, 2);
$out = $repo . '/var/rag-answers/' . date('Y-m-d_His') . (isset($options['label']) ? '-' . preg_replace('/[^\w-]+/', '-', $options['label']) : '');
mkdir($out, 0777, true);
// Outside this repository: inside it, the copy would sit in an ignored
// directory, and git would list none of its files — no documentation at all.
$copy = sys_get_temp_dir() . '/sherpa-rag-answers-' . bin2hex(random_bytes(4)) . '/' . basename($source);
mkdir($copy, 0777, true);
// Gone however the run ends: it is a whole copy of someone's project.
register_shutdown_function(static fn() => (new Process(['rm', '-rf', dirname($copy)]))->run());

// ---- the project, copied ----------------------------------------------------
// What a clone would hold, plus what the user has not committed yet: the same
// files DocSource would read, and the code beside them.
$git = new Process(['git', '-C', $source, 'ls-files', '-z', '--cached', '--others', '--exclude-standard']);
$git->run();
if ($git->isSuccessful()) {
    foreach (array_filter(explode("\0", $git->getOutput())) as $path) {
        if (is_file("{$source}/{$path}") && !is_link("{$source}/{$path}")) {
            @mkdir(dirname("{$copy}/{$path}"), 0777, true);
            copy("{$source}/{$path}", "{$copy}/{$path}");
        }
    }
} else {
    (new Process(['cp', '-a', $source . '/.', $copy]))->mustRun();
}

// ---- the index, built once ----------------------------------------------------
// Each question runs with a HOME of its own, so that nothing one session
// remembers reaches the next. The index is the same for all of them, and
// embedding it again for each would only cost time.
$index = $out . '/docs.db';
$docs = new DocIndex();
$docs->open($index);
(new Ingestor(new DocSource(), new Chunker()))->sync($copy, $docs);
if ($embeddingModel !== '') {
    $platform = new OpenAiCompatiblePlatform(HttpClient::create(), $url, $model, (string) getenv('SHERPA_API_KEY'));
    try {
        (new ChunkEmbedder())->embed($docs, new FixedEmbeddings($platform, $embeddingModel));
    } catch (\Throwable $e) {
        // A quota spent before the first question: nothing measured, and
        // better said in one line than in a stack trace.
        fwrite(STDERR, "\n  Cannot embed, nothing is measured: " . $e->getMessage() . "\n\n");
        exit(1);
    }
}
$counts = $docs->counts();
unset($docs);

printf("\n  %s · %d documents, %d passages%s\n  %d question(s) · %s\n\n",
    basename($source), $counts['documents'], $counts['chunks'],
    $embeddingModel !== '' ? " · vectors {$embeddingModel}" : ' · keywords only', $only === null ? count($questions) : count($only), $model);

// ---- one question, one session ---------------------------------------------------

/** @return array<string, mixed> */
function ask(string $repo, string $dir, string $copy, string $index, string $name, string $model, string $embeddingModel, string $question, int $timeout): array
{
    $home = "{$dir}/home";
    $config = "{$home}/.config/sherpa";
    mkdir("{$config}/projects/eval", 0777, true);
    copy($index, "{$config}/projects/eval/docs.db");

    $api = ['model' => $model, 'context' => 32768];
    if ($embeddingModel !== '') {
        $api['embedding_model'] = $embeddingModel;
    }
    file_put_contents("{$config}/config.yaml", Yaml::dump(['backend' => 'api', 'api' => $api], 4, 2));

    // No standing grant: reading is all a question needs, and a write the
    // agent attempts is refused — the next line it reads is /exit.
    $now = date('c');
    file_put_contents("{$config}/projects.yaml", Yaml::dump(['projects' => ['eval' => [
        'name'          => $name,
        'path'          => $copy,
        'memory_db'     => "{$config}/projects/eval/memory.db",
        'docker'        => ['enabled' => false, 'container' => '', 'db_container' => ''],
        'stack'         => '',
        'created_at'    => $now,
        'last_used_at'  => $now,
        'allowed_tools' => [],
    ]]], 5, 2));

    $transcript = "{$dir}/transcript.json";
    $process = new Process([PHP_BINARY, 'bin/sherpa'], cwd: $repo, env: [
        'HOME'                      => $home,
        'SHERPA_BACKEND'            => 'api',
        'SHERPA_CWD'                => $copy,
        'SHERPA_TRANSCRIPT'         => $transcript,
        // A runaway loop costs this much at most. A long search costs up to
        // ~110k tokens with a 32k window; the cap stays above that.
        'SHERPA_MAX_SESSION_TOKENS' => '200000',
        'SHERPA_FACT_EXTRACTION'    => 'off',
    ], input: $question . "\n/exit\n", timeout: $timeout);

    $started = microtime(true);
    try {
        $process->run();
    } catch (Symfony\Component\Process\Exception\ProcessTimedOutException) {
    }
    $output = (string) preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $process->getOutput() . $process->getErrorOutput());
    file_put_contents("{$dir}/output.txt", $output);

    $data = is_file($transcript) ? json_decode((string) file_get_contents($transcript), true) : null;

    return [
        'seconds'    => microtime(true) - $started,
        'transcript' => is_array($data) ? $data : [],
        'capped'     => str_contains($output, 'SHERPA_MAX_SESSION_TOKENS'),
        'unsaved'    => str_contains($output, "Impossible d'enregistrer la conversation"),
    ];
}

/**
 * What the session did: the tools called, what doc_search sent back, and the
 * last thing said to the user.
 *
 * @return array{tools: list<string>, searches: list<string>, results: list<string>, answer: string}
 */
function read(array $transcript): array
{
    $tools = [];
    $searches = [];
    $results = [];
    $answer = '';
    $isSearch = [];

    foreach ($transcript['messages'] ?? [] as $message) {
        foreach ($message['tool_calls'] ?? [] as $call) {
            $name = (string) ($call['function']['name'] ?? '?');
            $tools[] = $name;
            if ($name === 'doc_search') {
                $arguments = $call['function']['arguments'] ?? [];
                $arguments = is_string($arguments) ? (json_decode($arguments, true) ?? []) : $arguments;
                $searches[] = (string) ($arguments['query'] ?? '');
                $isSearch[$call['id'] ?? ''] = true;
            }
        }

        if (($message['role'] ?? '') === 'tool' && isset($isSearch[$message['tool_call_id'] ?? ''])) {
            $results[] = (string) ($message['content'] ?? '');
        }

        // A summary of the history is not an answer, though it reads like one.
        $content = (string) ($message['content'] ?? '');
        if (($message['role'] ?? '') === 'assistant' && empty($message['tool_calls']) && trim($content) !== ''
            && !str_starts_with($content, '[Summary of the earlier turns]')) {
            $answer = $content;
        }
    }

    return ['tools' => $tools, 'searches' => $searches, 'results' => $results, 'answer' => $answer];
}

/** A line naming the expected file, and the section when one is expected. */
function mentions(string $text, array $expect): bool
{
    foreach ($expect as $target) {
        foreach (explode("\n", $text) as $line) {
            if (str_contains($line, $target['path']) && ($target['section'] === '' || mb_stripos($line, $target['section']) !== false)) {
                return true;
            }
        }
    }

    return false;
}

function mentionsFile(string $text, array $expect): bool
{
    foreach ($expect as $target) {
        if (str_contains($text, $target['path'])) {
            return true;
        }
    }

    return false;
}

/**
 * An answer that says the documentation does not cover it. A heuristic, in
 * both languages the answers come in; the answers file is there to check it.
 */
function declines(string $answer): bool
{
    $answer = str_replace('’', "'", $answer);

    return preg_match('/\b(ne (le |l\'|en |y )?(précise|mentionne|dit|indique|documente|contient|décrit|trouve|couvre|aborde|évoque|parle)|n\'(indique|évoque|aborde|explique|y a|est pas (documenté|mentionné|précisé|décrit))|aucune (information|mention|trace|section|documentation|procédure)|pas d\'information|pas (documenté|mentionné|précisé|trouvé)|introuvable|not (documented|mentioned|specified|covered|described)|(doesn\'t|does not|don\'t) (say|mention|cover|describe|document|specify)|no (information|mention|documentation))/iu', $answer) === 1;
}

$rows = [];
$report = "# The agent's answers · " . basename($source) . " · {$model}\n";

$attempts = [];
foreach ($questions as $i => $question) {
    if ($only === null || in_array($i + 1, $only, true)) {
        for ($attempt = 1; $attempt <= $repeat; $attempt++) {
            $attempts[] = [$i + 1, $attempt, $question];
        }
    }
}

foreach ($attempts as [$n, $attempt, $question]) {
    $dir = sprintf('%s/q%02d', $out, $n) . ($repeat > 1 ? "-{$attempt}" : '');
    mkdir($dir, 0777, true);
    $run = ask($repo, $dir, $copy, $index, basename($source), $model, $embeddingModel, (string) $question['q'], $timeout);
    $seen = read($run['transcript']);
    $expect = $question['expect'] ?? [];
    $answerable = $expect !== [];

    $row = [
        'n'        => $n,
        'attempt'  => $attempt,
        'kind'     => (string) ($question['kind'] ?? ($answerable ? 'question' : 'hors doc')),
        'q'        => (string) $question['q'],
        'answered' => trim($seen['answer']) !== '',
        'searched' => count($seen['searches']),
        'found'    => $answerable ? mentions(implode("\n", $seen['results']), $expect) : null,
        'file'     => $answerable ? mentionsFile($seen['answer'], $expect) : null,
        'section'  => $answerable ? mentions($seen['answer'], $expect) : null,
        'declined' => $answerable ? null : declines($seen['answer']),
        'tools'    => array_count_values($seen['tools']),
        'requests' => (int) ($run['transcript']['usage']['requests'] ?? 0),
        'tokens'   => (int) (($run['transcript']['usage']['prompt'] ?? 0) + ($run['transcript']['usage']['completion'] ?? 0)),
        'seconds'  => round($run['seconds'], 1),
        'capped'   => $run['capped'],
        'unsaved'  => $run['unsaved'],
    ];
    $rows[] = $row;

    $yes = static fn(?bool $b) => $b === null ? '—' : ($b ? 'oui' : 'NON');
    printf("  %2d%s  %-12s searched %-3s  found %-3s  cites file %-3s section %-3s  %s  %3.0f s  %2d req%s%s\n",
        $n, $repeat > 1 ? ".{$attempt}" : '', mb_substr($row['kind'], 0, 12), $row['searched'] > 0 ? 'oui' : 'NON', $yes($row['found']),
        $yes($row['file']), $yes($row['section']), $answerable ? '           ' : 'says so ' . $yes($row['declined']), $row['seconds'], $row['requests'],
        $row['capped'] ? '  · token cap reached' : '', $row['unsaved'] ? '  · conversation not saved' : '');

    $report .= "\n## {$n}" . ($repeat > 1 ? ".{$attempt}" : '') . ". {$row['q']}\n\n"
        . '- expected: ' . ($answerable ? implode(' ; ', array_map(static fn($t) => $t['path'] . ($t['section'] !== '' ? ' › ' . $t['section'] : ''), $expect)) : 'nothing — the documentation does not answer') . "\n"
        . '- searches: ' . ($seen['searches'] === [] ? 'none' : implode(' · ', array_map(static fn($s) => "\"{$s}\"", $seen['searches']))) . "\n"
        . '- tools: ' . ($row['tools'] === [] ? 'none' : implode(', ', array_map(static fn($t, $c) => "{$t} ×{$c}", array_keys($row['tools']), $row['tools']))) . "\n\n"
        . ($seen['answer'] !== '' ? $seen['answer'] : '(no answer)') . "\n";
}

file_put_contents("{$out}/answers.md", $report);
file_put_contents("{$out}/results.json", json_encode(['model' => $model, 'embedding' => $embeddingModel, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// ---- totals ---------------------------------------------------------------------
$answerable = array_values(array_filter($rows, static fn($r) => $r['found'] !== null));
$outside = array_values(array_filter($rows, static fn($r) => $r['declined'] !== null));
$share = static fn(array $set, callable $test) => $set === [] ? '—' : sprintf('%d/%d', count(array_filter($set, $test)), count($set));

echo "\n  searched the documentation       " . $share($rows, static fn($r) => $r['searched'] > 0) . "\n";
echo '  found the right section          ' . $share($answerable, static fn($r) => $r['found']) . "\n";
echo '  cites the right file             ' . $share($answerable, static fn($r) => $r['file']) . "\n";
echo '  cites the right section          ' . $share($answerable, static fn($r) => $r['section']) . "\n";
echo '  says when the docs do not answer ' . $share($outside, static fn($r) => $r['declined']) . "\n";
echo '  answered                         ' . $share($rows, static fn($r) => $r['answered']) . "\n";
printf("  on average                       %.0f s, %.1f requests, %s tokens per question\n",
    array_sum(array_column($rows, 'seconds')) / max(1, count($rows)),
    array_sum(array_column($rows, 'requests')) / max(1, count($rows)),
    number_format(array_sum(array_column($rows, 'tokens')) / max(1, count($rows)), 0, ',', ' '));
echo "\n  Answers to read: {$out}/answers.md\n\n";
