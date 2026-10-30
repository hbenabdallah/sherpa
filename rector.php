<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        __DIR__ . '/tests/fixtures',
        // strict_types changes how scalars are passed: it comes in its own change, with the suites run on it.
        SafeDeclareStrictTypesRector::class,
        // A public signature is a contract with callers: changed on purpose, not as a side effect.
        RemoveUnusedPublicMethodParameterRector::class,
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
