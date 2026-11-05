<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Order;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Refberg\SyliusAutopayPlugin\Order\AutopayTransactionValidator;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Sylius\Component\Core\Model\Payment;
use Tests\Refberg\SyliusAutopayPlugin\Support\Credentials;

final class AutopayTransactionValidatorTest extends TestCase
{
    public function test_matching_transaction_is_valid(): void
    {
        self::assertSame([], self::validate(new AutopayTransaction('O1', 'R1', 'SUCCESS', 1500, 'PLN', '100001')));
        self::assertSame([], self::validate(new AutopayTransaction('O1', null, 'SUCCESS', 1500, 'PLN', null)));
    }

    public function test_every_mismatch_is_reported(): void
    {
        self::assertSame([
            'OrderID is not linked to this payment and service ID',
            'service ID does not match',
            'amount does not match',
            'currency does not match',
        ], self::validate(new AutopayTransaction('O2', 'R1', 'SUCCESS', 1499, 'EUR', '999')));
    }

    public function test_order_of_another_service_is_not_linked(): void
    {
        self::assertSame(['OrderID is not linked to this payment and service ID'], self::validate(
            new AutopayTransaction('O1', 'R1', 'SUCCESS', 1500, 'PLN', null),
            Credentials::fake('555'),
        ));
    }

    /**
     * @return list<string>
     */
    private static function validate(AutopayTransaction $transaction, ?AutopayCredentials $credentials = null): array
    {
        $payment = new Payment();
        $payment->setAmount(1500);
        $payment->setCurrencyCode('PLN');
        $details = PaymentDetails::of($payment);
        $details->addOrder('O1', '100001', 'R1', 1500, 'PLN');
        $details->save();

        return (new AutopayTransactionValidator())->validate($transaction, $payment, $credentials ?? Credentials::fake());
    }
}
