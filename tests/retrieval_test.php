<?php
// Retrieval that does not cost a turn: what compaction takes out of the window
// and can hand back, and the situational signals that rank the memory block
// before anyone has asked anything.

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Agent\ContextBudget;
use App\Memory\ContextStore;
use App\Project\RecentActivity;
use App\Tool\ContextRecallTool;

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

// ---- ContextStore: elision becomes swap ------------------------------------
$store = new ContextStore();
$store->open();

check('an empty store holds nothing', $store->count() === 0);
check('and searching it finds nothing', $store->search('quoi que ce soit', 4000) === '');

$controller = implode("\n", [
    '<?php',
    'namespace App\Controller;',
    '',
    'class PaymentController',
    '{',
    '    public function checkout(Request $request): Response',
    '    {',
    '        $intent = $this->stripe->createIntent($request->get("amount"));',
    '',
    '        return $this->json(["client_secret" => $intent->clientSecret]);',
    '    }',
    '',
    '    public function webhook(Request $request): Response',
    '    {',
    '        return new Response("", 204);',
    '    }',
    '}',
]);

$id = $store->keep('file_read', $controller);
check('an elided output is kept', $store->count() === 1);
check('and it hands back an id the stub can quote', $id > 0, (string) $id);

// The whole point: the content comes back without re-running the tool.
$found = $store->search('createIntent', 4000);
check('a keyword finds the passage again', str_contains($found, 'createIntent'), $found);
check('the excerpt carries line numbers', (bool) preg_match('/^\s+8 \|/m', $found), $found);
check('and names which output it came from', str_contains($found, "#{$id}") && str_contains($found, 'file_read'), $found);

// Context lines either side, not the bare hit.
check('surrounding lines come with it', str_contains($found, 'public function checkout'), $found);

// Not the whole file: that would undo the compaction that put it here.
check('but not the entire excerpt', !str_contains($found, 'namespace App\Controller'), $found);

// Two distant hits must not be silently spliced into one run.
$gapped = $store->search('class webhook', 4000);
check('a gap between two runs is marked', str_contains($gapped, '…'), $gapped);

check('a miss says so rather than guessing', $store->search('kubernetes', 4000) === '');

// Retrieval by id, for the stub that quoted one.
$whole = $store->get($id, 4000);
check('an id returns the output in full', $whole !== null && str_contains($whole, 'namespace App\Controller'), (string) $whole);
check('an unknown id returns nothing', $store->get(9999, 4000) === null);

// The cap is not ceremony: handing back everything undoes the compaction.
$store->keep('shell_exec', str_repeat("ligne de sortie tres longue\n", 2000));
$capped = $store->get(2, 500);
check('a large output is truncated to the budget', $capped !== null && mb_strlen($capped) < 700, (string) mb_strlen((string) $capped));
check('and says that it was', str_contains((string) $capped, 'tronqué'), (string) $capped);

$store->clear();
check('clearing empties it', $store->count() === 0);

// ---- the tool ---------------------------------------------------------------
$fresh = new ContextStore();
$fresh->open();
$tool = new ContextRecallTool($fresh, new ContextBudget(contextWindow: 32768));

check('with nothing elided, the tool says so plainly',
    str_contains($tool('quoi que ce soit'), 'Rien n\'a encore été retiré'), $tool('x'));

$fresh->keep('project_grep', "src/Payment/Checkout.php:42:    private StripeClient \$stripe;\n");
check('a keyword search reaches the store', str_contains($tool('stripe'), 'StripeClient'), $tool('stripe'));
check('a search with no match is reported, not thrown',
    str_contains($tool('mongodb'), 'Aucun passage'), $tool('mongodb'));
check('an empty call asks for something to go on',
    str_contains($tool(), 'mots-clés'), $tool());
check('a bad id falls back to suggesting a search',
    str_contains($tool(null, 9999), 'context_recall'), $tool(null, 9999));

// ---- RecentActivity: the situation as a query ------------------------------
$activity = new RecentActivity();

$terms = $activity->termsFrom([
    'src/Payment/StripeGateway.php',
    'src/Payment/Intent.php',
    'tests/payment_test.php',
], '/home/x/projet');

check('a touched directory becomes a term', in_array('payment', $terms, true), implode(',', $terms));
check('so does a class name', in_array('stripegateway', $terms, true), implode(',', $terms));
check('CamelCase is split so the domain word survives', in_array('stripe', $terms, true), implode(',', $terms));
check('the extension is dropped', !in_array('php', $terms, true), implode(',', $terms));

// Words every project shares would boost everything, which boosts nothing.
check('generic path segments are dropped', !in_array('src', $terms, true) && !in_array('tests', $terms, true), implode(',', $terms));

// The launch directory is the cheapest signal there is, and it leads.
$terms = $activity->termsFrom(['src/Mailer/Transport.php'], '/home/x/projet', '/home/x/projet/src/Invoicing');
check('the launch directory is a term', in_array('invoicing', $terms, true), implode(',', $terms));
check('and it outranks what git reported', array_search('invoicing', $terms, true) < array_search('mailer', $terms, true), implode(',', $terms));

check('a launch outside the project contributes nothing',
    !in_array('ailleurs', $activity->termsFrom([], '/home/x/projet', '/home/x/ailleurs'), true));
check('launching at the project root contributes nothing',
    $activity->termsFrom([], '/home/x/projet', '/home/x/projet') === []);

// A project with no repository must cost the ranking its boost and nothing else.
$bare = sys_get_temp_dir() . '/sherpa-norepo-' . bin2hex(random_bytes(4));
mkdir($bare, 0777, true);
check('a project without git yields no terms rather than failing', $activity->terms($bare) === []);
exec('rm -rf ' . escapeshellarg($bare));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
