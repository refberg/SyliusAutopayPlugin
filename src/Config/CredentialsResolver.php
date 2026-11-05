<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Config;

use Refberg\SyliusAutopayPlugin\AutopayGateway;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use function in_array;
use function is_scalar;
use function preg_match;
use function sprintf;
use function strtoupper;
use function trim;

final readonly class CredentialsResolver implements CredentialsResolverInterface
{
    public function supports(PaymentMethodInterface $paymentMethod): bool
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        if (!$gatewayConfig instanceof GatewayConfigInterface) {
            return false;
        }

        $factory = $gatewayConfig->getConfig()['factory'] ?? $gatewayConfig->getFactoryName();

        return AutopayGateway::FACTORY_NAME === $factory;
    }

    public function resolve(PaymentMethodInterface $paymentMethod): AutopayCredentials
    {
        if (!$this->supports($paymentMethod)) {
            throw new InvalidConfigurationException(sprintf('Payment method "%s" is not an Autopay payment method.', (string) $paymentMethod->getCode()));
        }

        /** @var array<string, mixed> $config */
        $config = $paymentMethod->getGatewayConfig()?->getConfig() ?? [];

        $environment = Environment::tryFrom(self::string($config, AutopayGateway::CONFIG_ENVIRONMENT));
        if (null === $environment) {
            throw new InvalidConfigurationException(sprintf('Payment method "%s" has no valid Autopay environment.', (string) $paymentMethod->getCode()));
        }

        // Older configurations without the setting use the Autopay default.
        $algorithm = self::string($config, AutopayGateway::CONFIG_HASH_ALGORITHM);
        $hashAlgorithm = '' === $algorithm ? HashAlgorithm::Sha256 : HashAlgorithm::tryFrom($algorithm);
        if (null === $hashAlgorithm) {
            throw new InvalidConfigurationException(sprintf('Payment method "%s" has an unsupported Autopay hash algorithm.', (string) $paymentMethod->getCode()));
        }

        $serviceId = self::string($config, AutopayGateway::CONFIG_SERVICE_ID);
        if (1 !== preg_match('/^\d+$/', $serviceId)) {
            throw new InvalidConfigurationException(sprintf('Payment method "%s" is missing the Autopay "%s" setting.', (string) $paymentMethod->getCode(), AutopayGateway::CONFIG_SERVICE_ID));
        }

        $sharedKey = self::string($config, AutopayGateway::CONFIG_SHARED_KEY);
        if ('' === $sharedKey) {
            throw new InvalidConfigurationException(sprintf('Payment method "%s" is missing the Autopay "%s" setting.', (string) $paymentMethod->getCode(), AutopayGateway::CONFIG_SHARED_KEY));
        }

        // Older configurations without the setting use the Autopay default.
        $currency = strtoupper(self::string($config, AutopayGateway::CONFIG_CURRENCY));
        $currency = '' === $currency ? AutopayGateway::DEFAULT_CURRENCY : $currency;
        if (!in_array($currency, AutopayGateway::CURRENCIES, true)) {
            throw new InvalidConfigurationException(sprintf('Payment method "%s" has an unsupported Autopay currency.', (string) $paymentMethod->getCode()));
        }

        return new AutopayCredentials($serviceId, $sharedKey, $hashAlgorithm, $environment, $currency);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function string(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
