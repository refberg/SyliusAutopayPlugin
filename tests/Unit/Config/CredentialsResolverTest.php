<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Config;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\AutopayGateway;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolver;
use Refberg\SyliusAutopayPlugin\Config\Environment;
use Refberg\SyliusAutopayPlugin\Config\HashAlgorithm;
use Refberg\SyliusAutopayPlugin\Config\InvalidConfigurationException;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentMethod;
use function print_r;
use function serialize;

final class CredentialsResolverTest extends TestCase
{
    private const CONFIG = [
        'environment' => 'production',
        'service_id' => ' 100001 ',
        'shared_key' => 'secret-key',
        'hash_algorithm' => 'sha512',
        'currency' => 'eur',
    ];

    public function test_it_resolves_credentials(): void
    {
        $credentials = (new CredentialsResolver())->resolve(self::method(self::CONFIG));

        self::assertEquals(new AutopayCredentials('100001', 'secret-key', HashAlgorithm::Sha512, Environment::Production, 'EUR'), $credentials);
        self::assertSame('https://pay.autopay.eu', $credentials->environment->baseUrl());
        self::assertSame('https://testpay.autopay.eu', Environment::Sandbox->baseUrl());
    }

    public function test_missing_hash_algorithm_and_currency_default_to_the_autopay_defaults(): void
    {
        $config = self::CONFIG;
        unset($config['hash_algorithm'], $config['currency']);

        $credentials = (new CredentialsResolver())->resolve(self::method($config));
        self::assertSame(HashAlgorithm::Sha256, $credentials->hashAlgorithm);
        self::assertSame('PLN', $credentials->currency);
    }

    public function test_it_supports_only_the_autopay_factory(): void
    {
        $resolver = new CredentialsResolver();

        self::assertTrue($resolver->supports(self::method(self::CONFIG)));
        self::assertTrue($resolver->supports(self::method(['factory' => AutopayGateway::FACTORY_NAME], 'other')));
        self::assertFalse($resolver->supports(self::method(self::CONFIG, 'offline')));
        self::assertFalse($resolver->supports(new PaymentMethod()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'no environment' => [['environment' => ''] + self::CONFIG];
        yield 'unknown environment' => [['environment' => 'staging'] + self::CONFIG];
        yield 'no service id' => [['service_id' => ''] + self::CONFIG];
        yield 'non-numeric service id' => [['service_id' => '12a'] + self::CONFIG];
        yield 'no shared key' => [['shared_key' => '  '] + self::CONFIG];
        yield 'array shared key' => [['shared_key' => ['x']] + self::CONFIG];
        yield 'md5' => [['hash_algorithm' => 'md5'] + self::CONFIG];
        yield 'unsupported currency' => [['currency' => 'CZK'] + self::CONFIG];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_is_rejected(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new CredentialsResolver())->resolve(self::method($config));
    }

    public function test_other_gateways_are_rejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new CredentialsResolver())->resolve(self::method(self::CONFIG, 'offline'));
    }

    public function test_shared_key_is_kept_out_of_debug_output_and_serialization(): void
    {
        $credentials = (new CredentialsResolver())->resolve(self::method(self::CONFIG));

        self::assertStringNotContainsString('secret-key', print_r($credentials, true));

        $this->expectException(LogicException::class);
        serialize($credentials);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function method(array $config, string $factory = AutopayGateway::FACTORY_NAME): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factory);
        $gatewayConfig->setConfig($config);

        $method = new PaymentMethod();
        $method->setCode('AUTOPAY');
        $method->setGatewayConfig($gatewayConfig);

        return $method;
    }
}
