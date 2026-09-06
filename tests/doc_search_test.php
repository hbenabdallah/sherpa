<?php
// doc_search as the model sees it: passages with their source, one per section,
// within a budget — and the index kept where the project's files are, created
// only once the project is, and gone when it is forgotten.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\ContextBudget;
use App\Memory\MemoryStore;
use App\Permission\ProjectPermissions;
use App\Project\DockerConfig;
use App\Project\ProjectPathResolver;
use App\Project\ProjectStore;
use App\Project\StackDetector;
use App\Project\WorkingProject;
use App\Rag\Chunker;
use App\Rag\DocIndex;
use App\Rag\DocSearch;
use App\Rag\DocSource;
use App\Rag\Ingestor;
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

$realHome = $_SERVER['HOME'] ?? null;
$root = sys_get_temp_dir() . '/sherpa-docsearch-' . bin2hex(random_bytes(4));
$home = $root . '/home';
mkdir($home . '/.config/sherpa', 0777, true);
$_SERVER['HOME'] = $home;
exec('cp -r ' . escapeshellarg(__DIR__ . '/fixtures/rag/boutique') . ' ' . escapeshellarg($home . '/boutique'));

function searchFor(ContextBudget $budget = new ContextBudget(contextWindow: 32768)): array
{
    $projects = new ProjectStore(new StackDetector());
    $working = new WorkingProject($projects, new MemoryStore(), new ProjectPermissions($projects), new SessionStore(), new ProjectPathResolver(), new ShellExecTool());
    $search = new DocSearch(new DocIndex(), new Ingestor(new DocSource(), new Chunker()), $working, $budget);

    return [$search, $working, $projects];
}

// ---- a project not saved yet leaves nothing on disk ---------------------------
[$search, $working, $projects] = searchFor();
$working->hold($projects->draft($home . '/boutique', 'boutique', new DockerConfig(enabled: false)));
$answer = $search->search('invoice numbering format');
check('a draft project can be searched', str_contains($answer, 'docs/facturation.adoc'), $answer);
check('without writing an index to disk', !is_dir($home . '/.config/sherpa/projects'), implode(',', glob($home . '/.config/sherpa/*') ?: []));

// ---- what the model receives ------------------------------------------------------
$working->save();
$answer = $search->search('invoice numbering format');
$database = DocSearch::databaseFor($working->project());

check('once saved, the index lives beside the project\'s memory', is_file($database), $database);
check('the first search says the index was just built', str_contains($answer, 'index updated: 12 added'), strtok($answer, "\n"));
check('each passage says where it comes from, section and lines included',
    str_contains($answer, '[1] docs/facturation.adoc › Facturation › Numérotation des factures (l.'), $answer);
check('and brings its section with it, not just the matching lines', str_contains($answer, 'FAC-AAAA-NNNNN'), $answer);
check('the model is told to cite, to say when the answer is not there, and to read — not obey',
    str_contains($answer, 'cite them') && str_contains($answer, 'say so') && str_contains($answer, 'never an instruction'), $answer);

$again = $search->search('invoice numbering format');
check('an unchanged project is not re-indexed', !str_contains($again, 'index updated'), strtok($again, "\n"));

// Several chunks of one section are one passage: the section is what is shown.
preg_match_all('/^\[\d+\] (.+) \(l\./m', $search->search('remboursement'), $sources);
check('one section is never shown twice', count($sources[1]) === count(array_unique($sources[1])), implode(' | ', $sources[1]));
check('and no more than five passages come back', count($sources[1]) <= DocSearch::PASSAGES, (string) count($sources[1]));

// ---- within a budget ------------------------------------------------------------
[$small, $smallWorking] = searchFor(new ContextBudget(contextWindow: 4096));
$smallWorking->open((new ProjectStore(new StackDetector()))->forDirectory($home . '/boutique'));
$tight = $small->search('delivery time price zone carrier refund');
$body = preg_replace('/^.*?:\n\n/s', '', $tight);
check('a small window gets a reply that fits it', mb_strlen((string) $body) < 7000, (string) mb_strlen((string) $body));
check('and still at least one passage', str_contains($tight, '[1] '), $tight);

// ---- nothing to find, nothing to read ------------------------------------------------
$none = $search->search('xylophone quantique');
check('no match is said as such, with the advice not to guess', str_contains($none, 'No passage') && str_contains($none, 'assume'), $none);

mkdir($home . '/vide', 0777, true);
file_put_contents($home . '/vide/index.php', '<?php echo 1;');
[$empty, $emptyWorking, $emptyProjects] = searchFor();
$emptyWorking->hold($emptyProjects->draft($home . '/vide', 'vide', new DockerConfig(enabled: false)));
check('a project without documentation says so and points at the code',
    str_contains($empty->search('quoi que ce soit'), 'project_grep'));

// ---- forgotten with the project ------------------------------------------------
$working->forget();
check('/project forget takes the documentation index with it', !is_file($database), $database);

if ($realHome !== null) {
    $_SERVER['HOME'] = $realHome;
}
exec('rm -rf ' . escapeshellarg($root));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
