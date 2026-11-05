<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/config',
    ])
    ->withSkip([
        __DIR__ . '/tests/TestApplication',
        // Private static helpers are intentional.
        Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector::class,
    ])
    // PHP 8.2 is the lowest supported version, so no newer syntax is introduced.
    ->withPhpSets(php82: true)
    ->withAttributesSets(symfony: true, phpunit: true)
    ->withComposerBased(phpunit: true, symfony: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withImportNames(importShortClasses: true, removeUnusedImports: true);
