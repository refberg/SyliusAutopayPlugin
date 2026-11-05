<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Integration;

use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use function array_map;
use function array_values;

/**
 * Autopay accepts one currency per service ID (PLN for the fake service).
 */
final class CurrencyTest extends IntegrationTestCase
{
    public function test_autopay_method_is_offered_only_for_payments_in_the_service_currency(): void
    {
        $payment = $this->createPlacedOrder();
        $resolver = self::service(PaymentMethodsResolverInterface::class, 'sylius.resolver.payment_methods');

        self::assertSame([self::METHOD_CODE], self::codes($resolver->getSupportedMethods($payment)));

        $payment->setCurrencyCode('EUR');
        self::assertSame([], self::codes($resolver->getSupportedMethods($payment)));
    }

    public function test_capture_in_another_currency_fails_without_calling_autopay(): void
    {
        $payment = $this->createPlacedOrder();
        $payment->setCurrencyCode('EUR');
        $this->em()->flush();

        $created = $this->postPaymentRequest(['paymentId' => $payment->getId(), 'paymentMethodCode' => self::METHOD_CODE]);

        // Sylius already refuses the method for this payment, or the plugin fails the capture.
        self::assertSame([], $this->autopay->orderIds());
        self::assertNotSame('processing', $created['state'] ?? null);
    }

    /**
     * @param array<PaymentMethodInterface> $methods
     *
     * @return list<string>
     */
    private static function codes(array $methods): array
    {
        return array_values(array_map(static fn (PaymentMethodInterface $method): string => (string) $method->getCode(), $methods));
    }
}
