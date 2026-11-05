<?php

declare(strict_types=1);

// Gives phpstan-doctrine access to the Doctrine mapping of the Sylius test application.

use Sylius\TestApplication\Kernel;

require __DIR__ . '/../vendor/sylius/test-application/config/bootstrap.php';

$kernel = new Kernel('test', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
