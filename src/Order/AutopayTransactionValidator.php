<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Order;

use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Checks that a transaction reported by Autopay really belongs to the given Sylius payment:
 * OrderID linked to the payment, service ID, amount and currency.
 */
final readonly class AutopayTransactionValidator implements AutopayTransactionValidatorInterface
{
    public function validate(AutopayTransaction $transaction, PaymentInterface $payment, AutopayCredentials $credentials): array
    {
        $errors = [];

        if (!PaymentDetails::of($payment)->hasOrderOfService($transaction->orderId, $credentials->serviceId)) {
            $errors[] = 'OrderID is not linked to this payment and service ID';
        }

        if (null !== $transaction->serviceId && $transaction->serviceId !== $credentials->serviceId) {
            $errors[] = 'service ID does not match';
        }

        if ($transaction->amount !== $payment->getAmount()) {
            $errors[] = 'amount does not match';
        }

        if ($transaction->currency !== $payment->getCurrencyCode()) {
            $errors[] = 'currency does not match';
        }

        return $errors;
    }
}
