<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Support;

use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Refberg\SyliusAutopayPlugin\Config\Environment;
use Refberg\SyliusAutopayPlugin\Config\HashAlgorithm;

final class Credentials
{
    public static function fake(string $serviceId = FakeAutopay::SERVICE_ID, string $sharedKey = FakeAutopay::SHARED_KEY): AutopayCredentials
    {
        return new AutopayCredentials($serviceId, $sharedKey, HashAlgorithm::Sha256, Environment::Sandbox);
    }
}
