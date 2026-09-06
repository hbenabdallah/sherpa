<?php
// Retrieval, measured. A small documentation corpus written for this purpose,
// 32 questions written before any search ran, and the strategies side by side.
//
// The floors asserted below are deliberately low and only on what keyword
// search must get right — exact words, table rows. Rephrased and English
// questions are printed, not asserted: they are the gap the embeddings phase
// exists to close, and the numbers here are the baseline it will be judged on.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Rag\Bm25Retriever;
use App\Rag\Chunker;
use App\Rag\DocIndex;
use App\Rag\DocSource;
use App\Rag\Eval\RetrievalEval;
use App\Rag\FusedRetriever;
use App\Rag\Ingestor;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr($detail, 0, 600), "\n";
    }
}

$corpus = __DIR__ . '/fixtures/rag/boutique';
$questions = json_decode((string) file_get_contents(__DIR__ . '/fixtures/rag/questions.json'), true);

$index = new DocIndex();
$index->open(':memory:');
$report = (new Ingestor(new DocSource(), new Chunker()))->sync($corpus, $index);

check('the whole corpus is indexed, every format included', $report->added === 12 && $report->failed === [], $report->summary() . ' ' . json_encode($report->failed));
check('32 questions to answer', count($questions) === 32);

$eval = new RetrievalEval();
$results = [
    'bm25'         => $eval->run(new Bm25Retriever($index), $questions),
    'bm25+racines' => $eval->run(new Bm25Retriever($index, stemmed: true), $questions),
    'rrf(les deux)' => $eval->run(new FusedRetriever([new Bm25Retriever($index), new Bm25Retriever($index, stemmed: true)]), $questions),
];

echo "\n" . RetrievalEval::table($results) . "\n\n";

foreach ($results as $name => $result) {
    foreach ($result['misses'] as $miss) {
        printf("  missed (%s, %s): %s\n      → %s\n", $name, $miss['kind'], $miss['q'], $miss['top']);
    }
}
echo "\n";

// What keyword search has no excuse to miss.
check('exact references are found in the first five', $results['bm25']['kinds']['exact']['rk'] >= 0.9,
    sprintf('%.0f%%', $results['bm25']['kinds']['exact']['rk'] * 100));
check('a value in a table is found through its row', $results['bm25']['kinds']['table']['rk'] >= 0.66,
    sprintf('%.0f%%', $results['bm25']['kinds']['table']['rk'] * 100));

// The default is the fusion because it measured best: plain BM25's precision
// and the stemmed variant's recall at once. Should that stop being true, the
// default has to be decided again — this is where it would show.
$fused = $results['rrf(les deux)']['overall'];
check('the fused default ranks as well as the best single strategy',
    $fused['mrr'] >= max($results['bm25']['overall']['mrr'], $results['bm25+racines']['overall']['mrr']),
    sprintf('MRR %.2f', $fused['mrr']));
check('and recalls as much as the best single strategy',
    $fused['rk'] >= max($results['bm25']['overall']['rk'], $results['bm25+racines']['overall']['rk']),
    sprintf('recall@5 %.0f%%', $fused['rk'] * 100));

// ---- incremental re-indexing -------------------------------------------------
$copy = sys_get_temp_dir() . '/sherpa-rag-' . bin2hex(random_bytes(4));
exec('cp -r ' . escapeshellarg($corpus) . ' ' . escapeshellarg($copy));
$index2 = new DocIndex();
$index2->open(':memory:');
$ingestor = new Ingestor(new DocSource(), new Chunker());
$ingestor->sync($copy, $index2);

$again = $ingestor->sync($copy, $index2);
check('a second sync with nothing changed reads nothing', !$again->changed() && $again->unchanged === 12, $again->summary());

file_put_contents($copy . '/docs/glossaire.md', "\n## Panier\n\nContenu en cours d'achat.\n", FILE_APPEND);
touch($copy . '/docs/support.txt', time() + 5);     // touched, not changed
unlink($copy . '/CHANGELOG.md');
file_put_contents($copy . '/docs/nouveau.md', "# New\n\nA document that was added.\n");

$delta = $ingestor->sync($copy, $index2);
check('only what changed is re-indexed', $delta->added === 1 && $delta->updated === 1 && $delta->removed === 1 && $delta->unchanged === 10, $delta->summary() . " / {$delta->unchanged} unchanged");
check('a new section is searchable at once', (new Bm25Retriever($index2))->retrieve('panier en cours', 3)[0]->chunk->path === 'docs/glossaire.md');
check('a removed document is no longer found', array_filter(
    (new Bm25Retriever($index2))->retrieve('paiement en trois fois', 5),
    fn($h) => $h->chunk->path === 'CHANGELOG.md',
) === []);
exec('rm -rf ' . escapeshellarg($copy));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
