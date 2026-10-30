<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        __DIR__ . '/tests/fixtures',
        // A public signature is a contract with callers: changed on purpose, not as a side effect.
        RemoveUnusedPublicMethodParameterRector::class,
        // The suites reach some private static methods through reflection, statically.
        LocallyCalledStaticMethodToNonStaticRector::class,
        // It promotes a property with the parameter's type, narrower than the property's:
        // Frontend could no longer take the editor's output in place of the terminal's.
        ClassPropertyAssignToConstructorPromotionRector::class,
        // Its parser shares state with closures by reference, which Rector reads as never changing:
        // it emptied the closure that ends a word, and every grant check with it.
        __DIR__ . '/src/Permission/ShellCommand.php',
    ])
    ->withCache(__DIR__ . '/var/rector')
    ->withPhpSets()
    ->withAttributesSets()
    ->withComposerBased(symfony: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        instanceOf: true,
    )
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withParallel();
