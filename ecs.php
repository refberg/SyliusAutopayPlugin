<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\FunctionNotation\NativeFunctionInvocationFixer;
use PhpCsFixer\Fixer\Import\GlobalNamespaceImportFixer;
use PhpCsFixer\Fixer\Import\OrderedImportsFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function (ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/config',
        __DIR__ . '/ecs.php',
        __DIR__ . '/rector.php',
        __DIR__ . '/composer-dependency-analyser.php',
    ]);

    $ecsConfig->skip([
        __DIR__ . '/tests/TestApplication/var',
        // DI configuration files call functions of the Symfony configurator namespace (service(), param(), ...),
        // which must not be turned into global function imports.
        NativeFunctionInvocationFixer::class => [__DIR__ . '/config', __DIR__ . '/tests/TestApplication/config'],
        GlobalNamespaceImportFixer::class => [__DIR__ . '/config', __DIR__ . '/tests/TestApplication/config'],
    ]);

    $ecsConfig->import('vendor/sylius-labs/coding-standard/ecs.php');

    // Stricter than the Sylius standard: global classes, functions and constants are imported with "use".
    $ecsConfig->ruleWithConfiguration(NativeFunctionInvocationFixer::class, [
        'include' => ['@all'],
        'scope' => 'all',
        'strict' => true,
    ]);
    $ecsConfig->ruleWithConfiguration(GlobalNamespaceImportFixer::class, [
        'import_classes' => true,
        'import_constants' => true,
        'import_functions' => true,
    ]);
    $ecsConfig->ruleWithConfiguration(OrderedImportsFixer::class, [
        'imports_order' => ['class', 'function', 'const'],
        'sort_algorithm' => 'alpha',
    ]);
};
