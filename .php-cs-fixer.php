<?php

use PhpCsFixer\{Config, Finder};

$rules = [
    '@PSR12' => true,
    '@Symfony' => true,
    'single_import_per_statement' => false,
    'group_import' => true,
    'concat_space' => [
        'spacing' => 'one',
    ],
    'return_assignment' => true,
    'ordered_class_elements' => true,
    'php_unit_construct' => true,
    'php_unit_dedicate_assert' => true,
    'php_unit_expectation' => true,
    'php_unit_test_case_static_method_calls' => [
        'call_type' => 'this',
    ],
    'phpdoc_to_comment' => false,
];

$finder = Finder::create()
    ->notPath('bootstrap')
    ->notPath('storage')
    ->notPath('vendor')
    ->notPath('packages')
    ->in(getcwd())
    ->name('*.php')
    ->notName('*.blade.php')
    ->notName('index.php')
    ->notName('_ide_helper.php')
    ->notName('server.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setFinder($finder)
    ->setRules($rules)
    ->setRiskyAllowed(true)
    ->setUsingCache(true);
