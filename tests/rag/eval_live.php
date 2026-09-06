<?php
// Retrieval measured with a real embedding model, on the same 32 questions as
// tests/rag_eval_test.php. Not part of `make test`: it calls the provider and
// costs a little — the corpus and the questions are a few thousand tokens.
//
//   SHERPA_API_URL, SHERPA_API_KEY   the provider, as for the chat
//   SHERPA_EMBEDDING_MODEL           e.g. @cf/baai/bge-m3
//
// make rag-eval, or directly: php tests/rag/eval_live.php
// On any project's documentation and its own questions:
//   php tests/rag/eval_live.php <project-dir> [<questions.json>]
// the questions defaulting to <project-dir>/.sherpa/rag-questions.json.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Platform\OpenAiCompatiblePlatform;
use App\Rag\Bm25Retriever;
use App\Rag\Chunker;
use App\Rag\DocIndex;
use App\Rag\DocSource;
use App\Rag\Embedding\ChunkEmbedder;
use App\Rag\Embedding\FixedEmbeddings;
use App\Rag\Eval\RetrievalEval;
use App\Rag\FusedRetriever;
use App\Rag\Ingestor;
use App\Rag\VectorRetriever;
use Symfony\Component\HttpClient\HttpClient;

$url = getenv('SHERPA_API_URL') ?: '';
$key = getenv('SHERPA_API_KEY') ?: '';
$model = getenv('SHERPA_EMBEDDING_MODEL') ?: '';

if ($url === '' || $model === '') {
    fwrite(STDERR, "SHERPA_API_URL and SHERPA_EMBEDDING_MODEL are required (and SHERPA_API_KEY if the provider needs one).\n");
    exit(2);
}

$corpus = $argv[1] ?? dirname(__DIR__) . '/fixtures/rag/boutique';
$questionFile = $argv[2] ?? (isset($argv[1]) ? $corpus . '/.sherpa/rag-questions.json' : dirname(__DIR__) . '/fixtures/rag/questions.json');
$questions = json_decode((string) file_get_contents($questionFile), true);

$index = new DocIndex();
$index->open(':memory:');
$report = (new Ingestor(new DocSource(), new Chunker()))->sync($corpus, $index);
$counts = $index->counts();

$embeddings = new FixedEmbeddings(new OpenAiCompatiblePlatform(HttpClient::create(), $url, 'inutile-ici', $key), $model);

$started = microtime(true);
$embedded = (new ChunkEmbedder())->embed($index, $embeddings);
$indexing = microtime(true) - $started;

printf("\n  Corpus: %d documents, %d passages · %d embedded in %.1f s with %s\n\n",
    $counts['documents'], $counts['chunks'], $embedded, $indexing, $model);

$bm25 = new Bm25Retriever($index);
$stems = new Bm25Retriever($index, stemmed: true);
$vectors = new VectorRetriever($index, $embeddings);

$strategies = [
    'bm25'                 => $bm25,
    'rrf(bm25,stems)'      => new FusedRetriever([$bm25, $stems]),
    'vectors'              => $vectors,
    'rrf(bm25,vectors)'    => new FusedRetriever([$bm25, $vectors]),
    // What DocSearch uses: the keywords count as one ranking against the vectors.
    'default'              => new FusedRetriever([$vectors, new FusedRetriever([$bm25, $stems])]),
];

$eval = new RetrievalEval();
$results = [];
$latency = [];
foreach ($strategies as $name => $retriever) {
    $t = microtime(true);
    $results[$name] = $eval->run($retriever, $questions);
    $latency[$name] = (microtime(true) - $t) / count($questions);
}

echo RetrievalEval::table($results) . "\n\n";

foreach ($latency as $name => $seconds) {
    printf("  %-20s %5.0f ms per question\n", $name, $seconds * 1000);
}
echo "\n";

foreach ($results as $name => $result) {
    foreach ($result['misses'] as $miss) {
        printf("  missed (%s, %s): %s\n      → %s\n", $name, $miss['kind'], $miss['q'], $miss['top']);
    }
}
