<?php
// Lance chaque fonction test_* de tests/*Test.php.

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/assert.php';

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$failed = 0;
$passed = 0;

foreach (get_defined_functions()['user'] as $function) {
    if (!str_starts_with($function, 'test_')) {
        continue;
    }

    try {
        $function();
        $passed++;
        echo "PASS {$function}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL {$function} : {$e->getMessage()}\n";
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
