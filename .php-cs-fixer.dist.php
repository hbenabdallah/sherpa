<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->ignoreVCSIgnored(true);

// strict_comparison, strict_param and date_time_immutable change behaviour, not layout:
// each is turned on by its own change, with the suites run on the result.
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/var/.php-cs-fixer.cache')
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        'declare_strict_types' => true,
        // final_class, with a way out: a class tagged @open, which says who extends it, stays open.
        'final_internal_class' => [
            'include' => [],
            'exclude' => ['open', 'final', 'Entity', 'ORM\\Entity', 'ORM\\Mapping\\Entity', 'Mapping\\Entity', 'Document', 'ODM\\Document'],
            'consider_absent_docblock_as_internal_class' => true,
        ],
        'modernize_types_casting' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
        'no_superfluous_elseif' => true,
        'no_useless_else' => true,
        'no_multiple_statements_per_line' => true,
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try', 'yield']],
        'class_attributes_separation' => ['elements' => ['const' => 'one', 'method' => 'one', 'property' => 'one']],
        'no_superfluous_phpdoc_tags' => ['remove_inheritdoc' => true],
        'phpdoc_trim' => true,
        'protected_to_private' => true,
        'self_accessor' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],
    ])
    ->setFinder($finder);
