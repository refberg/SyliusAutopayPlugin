<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->addPathToExclude(__DIR__ . '/tests/TestApplication')
    // The plugin uses the "http_client" service (HttpClientInterface), which requires symfony/http-client at runtime.
    ->ignoreErrorsOnPackage('symfony/http-client', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV]);
