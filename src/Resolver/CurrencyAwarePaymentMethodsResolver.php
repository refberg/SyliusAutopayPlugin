<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Resolver;

use Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusAutopayPlugin\Config\InvalidConfigurationException;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;

/**
 * Hides Autopay methods whose service does not accept the payment's currency (Autopay: one currency per service ID),
 * and misconfigured Autopay methods. Other methods are left as the decorated resolver returns them.
 *
 * @internal
 */
final readonly class CurrencyAwarePaymentMethodsResolver implements PaymentMethodsResolverInterface
{
    public function __construct(
        private PaymentMethodsResolverInterface $decorated,
        private CredentialsResolverInterface $credentialsResolver,
    ) {
    }

    public function getSupportedMethods(PaymentInterface $subject): array
    {
        $methods = [];
        foreach ($this->decorated->getSupportedMethods($subject) as $method) {
            if ($this->accepts($method, $subject)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    public function supports(PaymentInterface $subject): bool
    {
        return $this->decorated->supports($subject);
    }

    private function accepts(PaymentMethodInterface $method, PaymentInterface $subject): bool
    {
        if (!$this->credentialsResolver->supports($method)) {
            return true;
        }

        try {
            return $this->credentialsResolver->resolve($method)->currency === $subject->getCurrencyCode();
        } catch (InvalidConfigurationException) {
            return false;
        }
    }
}
