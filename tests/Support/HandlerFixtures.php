<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Support;

use Refberg\SyliusAutopayPlugin\AutopayGateway;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentRequest;

/**
 * Unsaved entities with fixed IDs for handler unit tests.
 */
final class HandlerFixtures
{
    public const HASH = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

    public static function method(string $code = 'AUTOPAY'): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(AutopayGateway::FACTORY_NAME);
        $gatewayConfig->setGatewayName('autopay');
        $gatewayConfig->setConfig([
            AutopayGateway::CONFIG_ENVIRONMENT => 'sandbox',
            AutopayGateway::CONFIG_SERVICE_ID => FakeAutopay::SERVICE_ID,
            AutopayGateway::CONFIG_SHARED_KEY => FakeAutopay::SHARED_KEY,
        ]);
        $method = new PaymentMethod();
        $method->setCode($code);
        $method->setGatewayConfig($gatewayConfig);

        return $method;
    }

    public static function payment(PaymentMethod $method, string $state): Payment
    {
        $payment = new class() extends Payment {
            public function getId(): int
            {
                return 42;
            }
        };
        $payment->setMethod($method);
        $payment->setAmount(1500);
        $payment->setCurrencyCode('PLN');
        $payment->setState($state);
        $order = new Order();
        $order->setNumber('000000001');
        $order->addPayment($payment);

        return $payment;
    }

    public static function paymentRequest(Payment $payment, PaymentMethod $method, string $action): PaymentRequest
    {
        $paymentRequest = new class($payment, $method) extends PaymentRequest {
            public function getId(): string
            {
                return HandlerFixtures::HASH;
            }
        };
        $paymentRequest->setAction($action);

        return $paymentRequest;
    }
}
