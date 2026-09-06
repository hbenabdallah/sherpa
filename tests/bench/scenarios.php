<?php
// The tasks the benchmark gives Sherpa. Each one is something a person would
// actually ask of a coding agent, on a project it has never seen, and each is
// scored on what happened to the project — files, tests, the answer given —
// never on how the terminal looked.
//
// A check returns true when it holds. Its label is what the report prints when
// it does not, so it is written as the thing that should have been true.

/** The bug several tasks start from: a subtotal that forgets the quantities. */
$forgetQuantities = function (BenchRun $run): void {
    $run->replaceIn(
        'src/Billing/Invoice.php',
        '$sum += $line->product->priceCents * $line->quantity;',
        '$sum += $line->product->priceCents;',
    );
};

$readOnly = fn(BenchRun $run) => $run->modified() === [];

return [
    [
        'name'    => 'database-question',
        'project' => 'boutique',
        'prompt'  => 'Which database does this project use in production, and in which file is that configured?',
        'checks'  => [
            'names PostgreSQL'                 => fn(BenchRun $r) => (bool) preg_match('/postgre|pgsql/i', $r->answer()),
            'cites the configuration file'     => fn(BenchRun $r) => (bool) preg_match('#config/database\.php|\.env\.example#', $r->answer()),
            'changes nothing'                  => $readOnly,
        ],
    ],
    [
        'name'    => 'find-vat',
        'project' => 'boutique',
        'prompt'  => 'Where is VAT calculated in this project? Give the file and the method name.',
        'checks'  => [
            'cites TaxCalculator'     => fn(BenchRun $r) => str_contains($r->answer(), 'TaxCalculator'),
            'cites the vatFor method' => fn(BenchRun $r) => str_contains($r->answer(), 'vatFor'),
            'changes nothing'         => $readOnly,
        ],
    ],
    [
        'name'    => 'list-routes',
        'project' => 'boutique',
        'prompt'  => 'List every HTTP route in this project, with the HTTP method and controller of each.',
        'checks'  => [
            'gives all four routes'   => fn(BenchRun $r) => array_filter(
                ['/products', '/products/{id}', '/cart/items', '/invoices/preview'],
                fn($route) => !str_contains($r->answer(), $route),
            ) === [],
            'changes nothing' => $readOnly,
        ],
    ],
    [
        'name'    => 'explain-bug',
        'project' => 'boutique',
        'setup'   => $forgetQuantities,
        'prompt'  => 'The invoice subtotal is wrong as soon as a line has a quantity above 1. Find the cause and explain it, without changing any file.',
        'checks'  => [
            'places the problem in Invoice'    => fn(BenchRun $r) => str_contains($r->answer(), 'Invoice'),
            'says the quantity is left out'    => fn(BenchRun $r) => (bool) preg_match('/\bquantity\b|multipli/i', $r->answer()),
            'changes nothing'                  => $readOnly,
        ],
    ],
    [
        'name'    => 'fix-bug',
        'project' => 'boutique',
        'setup'   => $forgetQuantities,
        'prompt'  => 'The tests fail. Find out why, fix the code (not the tests) and run the tests again to check.',
        'checks'  => [
            'the tests pass'                => fn(BenchRun $r) => $r->testsPass(),
            'the tests are untouched'       => fn(BenchRun $r) => array_filter($r->modified(), fn($f) => str_starts_with($f, 'tests/')) === [],
            'ran the tests itself'          => fn(BenchRun $r) => $r->ranCommand('run.php'),
        ],
    ],
    [
        'name'    => 'add-slugify',
        'project' => 'boutique',
        'prompt'  => 'Add to the Str class a static method slugify(string $text): string that lowercases the text, replaces accented letters with their unaccented equivalent, replaces every run of characters that are neither letters nor digits with a single dash, and strips dashes at the start and the end. Add a test in tests/StrTest.php and run the tests.',
        'checks'  => [
            'slugify does what was asked'     => fn(BenchRun $r) => $r->php(<<<'PHP'
                $cases = [
                    ['Élan Vital 2024 !', 'elan-vital-2024'],
                    ['  Déjà   vu ', 'deja-vu'],
                    ['Crème-brûlée', 'creme-brulee'],
                    ['Hello_World', 'hello-world'],
                ];
                foreach ($cases as [$in, $out]) {
                    $got = \Boutique\Support\Str::slugify($in);
                    if ($got !== $out) {
                        echo "{$in} → {$got} (expected {$out})";
                        exit(1);
                    }
                }
                PHP),
            'a test was added'      => fn(BenchRun $r) => str_contains($r->read('tests/StrTest.php'), 'slugify'),
            'the tests pass'        => fn(BenchRun $r) => $r->testsPass(),
        ],
    ],
    [
        'name'    => 'add-country',
        'project' => 'boutique',
        'prompt'  => 'Add Italian VAT (22 %) to the tax calculation, with a test that checks it, then run the tests.',
        'checks'  => [
            'Italian VAT is calculated'     => fn(BenchRun $r) => $r->php(<<<'PHP'
                $taxes = new \Boutique\Billing\TaxCalculator();
                if ($taxes->vatFor(1000, 'IT') !== 220) {
                    echo 'vatFor(1000, IT) = ', $taxes->vatFor(1000, 'IT');
                    exit(1);
                }
                PHP),
            'a test covers it'   => fn(BenchRun $r) => str_contains($r->read('tests/TaxCalculatorTest.php'), 'IT'),
            'the tests pass'     => fn(BenchRun $r) => $r->testsPass(),
        ],
    ],
    [
        // Nothing to write: the answer is in what the project's own command
        // prints, which the agent has to think of running.
        'name'    => 'count-tests',
        'project' => 'boutique',
        'prompt'  => 'How many tests does this project hold, and how many pass? Do not change any file.',
        'checks'  => [
            'gives the count'      => fn(BenchRun $r) => (bool) preg_match('/\b7\b/', $r->answer()),
            'ran the tests'        => fn(BenchRun $r) => $r->ranCommand('run.php'),
            'changes nothing'      => $readOnly,
        ],
    ],
    [
        // A trap: the test named here passes. The work is to check and say so,
        // not to "fix" something that is not broken.
        'name'    => 'dont-break-it',
        'project' => 'boutique',
        'prompt'  => 'Does the test test_invoice_total_adds_french_vat fail? Fix it only if it does.',
        'checks'  => [
            'says the test passes'  => fn(BenchRun $r) => (bool) preg_match('/\bpasses\b|\bpassing\b|does not fail|doesn\'t fail|no failure/iu', $r->answer()),
            'fixes nothing'         => $readOnly,
            'the tests still pass'  => fn(BenchRun $r) => $r->testsPass(),
        ],
    ],
    [
        'name'    => 'rename-class',
        'project' => 'boutique',
        'prompt'  => 'Rename the class UserRepo to UserRepository across the whole project, the file included, then run the tests.',
        'checks'  => [
            'no reference to UserRepo left'    => fn(BenchRun $r) => $r->grep('\bUserRepo\b') === [],
            'the file is renamed'              => fn(BenchRun $r) => $r->exists('src/User/UserRepository.php') && !$r->exists('src/User/UserRepo.php'),
            'the tests pass'                   => fn(BenchRun $r) => $r->testsPass(),
        ],
    ],
];
