<?php
// Ingestion: documents read with their structure, cut along their own seams.
// Most retrieval failures start here — a heading lost, a table flattened, a
// passage cut mid-sentence — long before any search runs.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Rag\Chunker;
use App\Rag\Parser\HtmlParser;
use App\Rag\Parser\MarkdownParser;
use App\Rag\Parser\TextParser;

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

$headings = fn($doc) => array_map(fn($s) => implode(' › ', $s->headings), $doc->sections);

// ---- Markdown ----------------------------------------------------------------
$md = <<<MD
---
title: Shop guide
---
Introduction before any heading.

# Orders

## Refunds

A customer can be refunded within 30 days.

```bash
# this is a shell comment, not a heading
make refund
```

## Delivery
Times within mainland France.

| Zone | Time |
| --- | --- |
| Paris | 2 days |
| Lyon | 3 days |

Installation
============

Run make install.
MD;

$doc = (new MarkdownParser())->parse('docs/guide.md', $md);
check('front matter gives the title', $doc->title === 'Shop guide', $doc->title);
check('front matter is not part of the text', !str_contains($doc->sections[0]->text, 'title:'), $doc->sections[0]->text);
check('text before any heading is its own section', $doc->sections[0]->headings === [] && str_contains($doc->sections[0]->text, 'Introduction'));
check('headings nest as a path', in_array('Orders › Refunds', $headings($doc), true), json_encode($headings($doc), JSON_UNESCAPED_UNICODE));
check('a "#" inside a code block is not a heading', !in_array('Orders › this is a shell comment, not a heading', $headings($doc), true)
    && str_contains($doc->sections[1]->text ?? '', 'make refund'), json_encode($headings($doc), JSON_UNESCAPED_UNICODE));
check('an underlined heading is a heading, at level one', in_array('Installation', $headings($doc), true), json_encode($headings($doc), JSON_UNESCAPED_UNICODE));
check('a heading with nothing under it is not a section, but stays in the path below',
    !in_array('Orders', $headings($doc), true));

$delivery = array_values(array_filter($doc->sections, fn($s) => ($s->headings[1] ?? '') === 'Delivery'))[0] ?? null;
check('a table is kept as written', str_contains($delivery?->text ?? '', '| Paris | 2 days |'), $delivery?->text ?? '');
check('line numbers point at the file: the section starts right under its heading', $delivery?->startLine === 18, (string) $livraison?->startLine);

// ---- HTML --------------------------------------------------------------------
$html = <<<HTML
<!doctype html><html><head><title>Prices</title><script>var x = "<h1>fake</h1>";</script></head>
<body><nav><a href="/">Home</a></nav>
<h1>Delivery prices</h1>
<p>Les délais dépendent de la zone.</p>
<h2>France</h2>
<table><tr><th>City</th><th>Time</th></tr><tr><td>Paris</td><td>2 days</td></tr><tr><td>Lyon</td><td>3 days</td></tr></table>
<ul><li>Tracking included</li><li>Insurance optional</li></ul>
<footer>© 2026</footer></body></html>
HTML;

$doc = (new HtmlParser())->parse('site/tarifs.html', $html);
check('an HTML title is read', $doc->title === 'Prices', $doc->title);
$france = array_values(array_filter($doc->sections, fn($s) => ($s->headings[1] ?? '') === 'France'))[0] ?? null;
check('HTML headings nest the same way', $france !== null, json_encode($headings($doc), JSON_UNESCAPED_UNICODE));
check('a table becomes a Markdown table, row by row, not a stream of cells',
    str_contains($france?->text ?? '', "| City | Time |\n| --- | --- |\n| Paris | 2 days |"), $france?->text ?? '');
check('list items stay list items', str_contains($france?->text ?? '', '- Tracking included'));
$all = implode("\n", array_map(fn($s) => $s->text, $doc->sections));
check('navigation, scripts and footers are dropped', !str_contains($all, 'Home') && !str_contains($all, 'fake') && !str_contains($all, '2026'), $all);
check('accents survive the DOM', str_contains($all, 'délais'), $all);

// ---- reStructuredText and AsciiDoc -------------------------------------------
$rst = "Déploiement\n===========\n\nIntro.\n\nProduction\n----------\n\nWe deploy on Tuesdays.\n";
$doc = (new TextParser())->parse('docs/deploy.rst', $rst);
check('reStructuredText levels come from the order of underlines', in_array('Déploiement › Production', $headings($doc), true), json_encode($headings($doc), JSON_UNESCAPED_UNICODE));

$adoc = "= Manual\n\n== Backups\n\nEvery night at 2am.\n";
$doc = (new TextParser())->parse('docs/manual.adoc', $adoc);
check('AsciiDoc headings too', in_array('Manual › Backups', $headings($doc), true) && $doc->title === 'Manual', json_encode($headings($doc), JSON_UNESCAPED_UNICODE));

$doc = (new TextParser())->parse('NOTES.txt', "First paragraph.\n\nSecond.");
check('plain text is one section named after the file', count($doc->sections) === 1 && $doc->title === 'NOTES');

// ---- chunking ----------------------------------------------------------------
$chunker = new Chunker();
$short = (new MarkdownParser())->parse('docs/short.md', "# Guide\n\n## Returns\n\nWithin 30 days.\n");
$chunks = $chunker->chunk($short);
check('a short section is one chunk', count($chunks) === 1);
check('which knows where it sits', $chunks[0]->context() === 'Guide › Returns', $chunks[0]->context());
check('and says where to find it', $chunks[0]->source() === 'docs/short.md › Guide › Returns (l. 5-5)', $chunks[0]->source());

// A long section: many paragraphs, a table in the middle.
$paragraphs = [];
for ($i = 1; $i <= 14; $i++) {
    $paragraphs[] = "Paragraph {$i}. " . str_repeat("A sentence about policy number {$i}, written out in full. ", 7);
}
array_splice($paragraphs, 7, 0, ["| Column | Value |\n| --- | --- |\n| a | 1 |\n| b | 2 |"]);
$long = (new MarkdownParser())->parse('docs/long.md', "# Policy\n\n" . implode("\n\n", $paragraphs) . "\n");
$chunks = $chunker->chunk($long);

check('a long section is cut into several chunks', count($chunks) > 2, (string) count($chunks));
check('no chunk goes past the ceiling', max(array_map(fn($c) => mb_strlen($c->text), $chunks)) <= Chunker::MAX);
// A chunk starts either at a paragraph, or with the sentences it repeats
// from the end of the one before — never in the middle of a sentence.
check('chunks start at a paragraph or a sentence, never mid-sentence',
    array_filter($chunks, fn($c) => !preg_match('/^(Paragraph \d+\.|A sentence|\| Column)/', $c->text)) === [],
    implode(' // ', array_map(fn($c) => mb_substr($c->text, 0, 30), $chunks)));
check('a table is never split between two chunks',
    array_filter($chunks, fn($c) => str_contains($c->text, '| Column') && !str_contains($c->text, '| b | 2 |')) === []);
// Paragraphs here are longer than the overlap: carrying whole paragraphs
// would carry nothing. The start of each chunk must repeat the end of the last.
$overlaps = true;
for ($i = 1, $n = count($chunks); $i < $n; $i++) {
    $head = explode("\n\n", $chunks[$i]->text)[0];
    if (!str_contains($chunks[$i - 1]->text, '| Column') && !str_ends_with($chunks[$i - 1]->text, $head)) {
        $overlaps = false;
    }
}
check('each chunk begins with the end of the one before, even when paragraphs are longer than the overlap',
    $overlaps, mb_substr($chunks[1]->text, 0, 80) . ' … vs … ' . mb_substr($chunks[0]->text, -80));
check('and the repeated part stays within the overlap',
    mb_strlen(explode("\n\n", $chunks[1]->text)[0]) <= Chunker::OVERLAP);
check('each chunk is shown with more than itself — its parent',
    mb_strlen($chunks[1]->parent) > mb_strlen($chunks[1]->text) && mb_strlen($chunks[1]->parent) <= Chunker::PARENT_MAX + 200);
check('line ranges grow with the chunks', $chunks[1]->startLine > $chunks[0]->startLine && $chunks[1]->endLine > $chunks[0]->endLine);

// A fenced block with blank lines inside is still one block.
$fenced = (new MarkdownParser())->parse('docs/code.md', "# Code\n\n```php\n\$a = 1;\n\n\$b = 2;\n```\n");
check('a code block with blank lines inside stays whole', count($chunker->chunk($fenced)) === 1 && str_contains($chunker->chunk($fenced)[0]->text, '$b = 2;'));

// ---- what counts as documentation ------------------------------------------
$tree = sys_get_temp_dir() . '/sherpa-docsource-' . bin2hex(random_bytes(4));
foreach (['README.md', 'docs/guide.html', 'docs/notes.txt', 'src/Payment/README.md', 'public/robots.txt',
          'public/index.html', 'templates/mail.html', 'requirements.txt', 'vendor/lib/README.md',
          'tests/fixtures/faux/README.md', 'app/assets/aide.txt'] as $file) {
    @mkdir(dirname($tree . '/' . $file), 0777, true);
    file_put_contents($tree . '/' . $file, "# x\n\ntexte\n");
}
$listed = (new App\Rag\DocSource())->files($tree);
check('documentation is found wherever it is written',
    array_diff(['README.md', 'docs/guide.html', 'docs/notes.txt', 'src/Payment/README.md'], $listed) === [], json_encode($listed));
check('but not files that only look like text: robots.txt, requirements.txt',
    !in_array('public/robots.txt', $listed, true) && !in_array('requirements.txt', $listed, true), json_encode($listed));
check('nor pages and text that belong to the application — public/, templates/, app/',
    !in_array('public/index.html', $listed, true) && !in_array('templates/mail.html', $listed, true) && !in_array('app/assets/aide.txt', $listed, true),
    json_encode($listed));
check('nor other people\'s documents, nor test fixtures', !in_array('vendor/lib/README.md', $listed, true) && !in_array('tests/fixtures/faux/README.md', $listed, true));
exec('rm -rf ' . escapeshellarg($tree));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
