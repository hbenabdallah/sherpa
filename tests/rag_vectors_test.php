<?php
// The vector path, without a network: a hashing stand-in plays the embedding
// model. What is tested is the plumbing — what gets embedded and when, what
// happens when the model changes or the provider fails. Whether vectors find
// better passages is measured with a real model in tests/rag/eval_live.php.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Memory\MemoryStore;
use App\Permission\ProjectPermissions;
use App\Project\ProjectPathResolver;
use App\Project\ProjectStore;
use App\Project\StackDetector;
use App\Project\DockerConfig;
use App\Project\WorkingProject;
use App\Rag\Bm25Retriever;
use App\Rag\Chunker;
use App\Rag\DocIndex;
use App\Rag\DocSearch;
use App\Rag\DocSource;
use App\Rag\Embedding\EmbeddingProvider;
use App\Rag\Embedding\HashingEmbeddings;
use App\Rag\Embedding\Vectors;
use App\Rag\FallbackRetriever;
use App\Rag\Ingestor;
use App\Rag\Retriever;
use App\Rag\VectorRetriever;
use App\Session\SessionStore;
use App\Tool\ShellExecTool;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("%s %s\n", $ok ? ' PASS' : ' FAIL', $label);
    if (!$ok && $detail !== '') {
        echo '        ', substr($detail, 0, 500), "\n";
    }
}

// ---- vector arithmetic --------------------------------------------------------
$v = Vectors::normalize([3.0, 4.0]);
check('vectors are scaled to unit length', abs($v[0] - 0.6) < 1e-9 && abs($v[1] - 0.8) < 1e-9);
check('so that cosine is a dot product', abs(Vectors::dot($v, $v) - 1.0) < 1e-9);
$round = Vectors::unpack(Vectors::pack([0.25, -0.5, 1.0]));
check('stored as float32, read back intact', $round === [0.25, -0.5, 1.0], json_encode($round));

// ---- a project, searched with vectors -----------------------------------------------
$realHome = $_SERVER['HOME'] ?? null;
$root = sys_get_temp_dir() . '/sherpa-vectors-' . bin2hex(random_bytes(4));
mkdir($root . '/home/.config/sherpa', 0777, true);
$_SERVER['HOME'] = $root . '/home';
exec('cp -r ' . escapeshellarg(__DIR__ . '/fixtures/rag/boutique') . ' ' . escapeshellarg($root . '/home/boutique'));

$projects = new ProjectStore(new StackDetector());
$working = new WorkingProject($projects, new MemoryStore(), new ProjectPermissions($projects), new SessionStore(), new ProjectPathResolver(), new ShellExecTool());
$working->hold($projects->draft($root . '/home/boutique', 'boutique', new DockerConfig(enabled: false)));
$working->save();

function searcher(WorkingProject $working, ?EmbeddingProvider $embeddings): DocSearch
{
    return new DocSearch(new DocIndex(), new Ingestor(new DocSource(), new Chunker()), $working, null, $embeddings);
}

$embeddings = new HashingEmbeddings('modele-a');
$search = searcher($working, $embeddings);
$first = $search->sync();
$chunks = $search->counts()['chunks'];

check('the first sync gives every chunk a vector', $first->embedded === $chunks && $embeddings->texts === $chunks, "{$first->embedded} / {$chunks}");
check('which /docs reports as searching by meaning and keywords', $search->mode()['mode'] === 'by meaning and by keywords', json_encode($search->mode(), JSON_UNESCAPED_UNICODE));
check('the vectors fused with the keywords, and the keywords alone behind them',
    preg_match('/^vectors\+[^,]+, else /u', $search->retriever()->name()) === 1, $search->retriever()->name());

$before = $embeddings->texts;
$search->sync();
check('an unchanged project embeds nothing again', $embeddings->texts === $before, (string) ($embeddings->texts - $before));

file_put_contents($root . '/home/boutique/docs/glossaire.md', "\n## Basket\n\nWhat the customer is about to buy.\n", FILE_APPEND);
$before = $embeddings->texts;
$changed = $search->sync();
check('a changed document has its own chunks embedded, nothing else', $changed->embedded > 0 && $changed->embedded < 6 && $embeddings->texts - $before === $changed->embedded,
    "{$changed->embedded} embedded");

$answer = $search->search('basket customer buy');
check('and what it says is found through the vectors', str_contains($answer, 'glossaire.md › Glossaire › Basket'), $answer);

// ---- another model, other vectors -------------------------------------------
$other = new HashingEmbeddings('modele-b', 128);
$search = searcher($working, $other);
$switched = $search->sync();
check('a new model re-embeds everything: two models\' vectors are never compared', $switched->embedded === $search->counts()['chunks'], (string) $switched->embedded);

$stale = new VectorRetriever((function () use ($working) { $i = new DocIndex(); $i->open(DocSearch::databaseFor($working->project())); return $i; })(), new HashingEmbeddings('modele-c'));
check('vectors from another model are never searched', $stale->retrieve('panier', 5) === []);

// ---- a provider that fails ------------------------------------------------------------
$broken = new class implements EmbeddingProvider {
    public function model(): string { return 'modele-en-panne'; }
    public function embed(array $texts): array { throw new RuntimeException('HTTP 429: quota exceeded'); }
};
$search = searcher($working, $broken);
$answer = $search->search('invoice numbering format');
check('a failing provider costs the vectors, not the search: keywords answer', str_contains($answer, 'docs/facturation.adoc'), $answer);
check('and /docs says why', str_contains($search->mode()['detail'], 'quota exceeded'), json_encode($search->mode(), JSON_UNESCAPED_UNICODE));

// ---- without a model ----------------------------------------------------------------------
$none = new HashingEmbeddings('');
$search = searcher($working, $none);
$search->sync();
check('no model configured: keywords, and nothing sent anywhere', $none->calls === 0 && $search->mode()['mode'] === 'by keywords');
check('with how to turn meaning on', str_contains($search->mode()['detail'], 'embedding_model'), $search->mode()['detail']);

// ---- falling back ---------------------------------------------------------------------
$failing = new class implements Retriever {
    public function name(): string { return 'panne'; }
    public function retrieve(string $question, int $limit): array { throw new RuntimeException('unreachable'); }
};
$index = new DocIndex();
$index->open(DocSearch::databaseFor($working->project()));
$fallback = new FallbackRetriever($failing, new Bm25Retriever($index));
check('a strategy that throws hands over to the other', $fallback->retrieve('factures', 3) !== [] && $fallback->lastError() === 'unreachable');

if ($realHome !== null) {
    $_SERVER['HOME'] = $realHome;
}
exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
