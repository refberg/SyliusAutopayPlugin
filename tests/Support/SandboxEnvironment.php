<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Support;

use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Refberg\SyliusAutopayPlugin\Config\Environment;
use Refberg\SyliusAutopayPlugin\Config\HashAlgorithm;
use function getenv;
use function is_string;

/**
 * Settings of the optional tests against the real Autopay test environment, read from environment variables.
 * Credentials are never stored in the repository.
 */
final class SandboxEnvironment
{
    public static function isEnabled(): bool
    {
        return '1' === self::value('AUTOPAY_SANDBOX');
    }

    public static function credentials(): ?AutopayCredentials
    {
        $serviceId = self::value('AUTOPAY_SANDBOX_SERVICE_ID');
        $sharedKey = self::value('AUTOPAY_SANDBOX_SHARED_KEY');
        if (null === $serviceId || null === $sharedKey) {
            return null;
        }

        $algorithm = HashAlgorithm::tryFrom(self::value('AUTOPAY_SANDBOX_HASH_ALGORITHM') ?? 'sha256') ?? HashAlgorithm::Sha256;

        return new AutopayCredentials($serviceId, $sharedKey, $algorithm, Environment::Sandbox);
    }

    /**
     * RemoteID of a transaction paid manually on the Autopay test page, for the refund test.
     */
    public static function paidRemoteId(): ?string
    {
        return self::value('AUTOPAY_SANDBOX_PAID_REMOTE_ID');
    }

    private static function value(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) && '' !== $value ? $value : null;
    }
}
