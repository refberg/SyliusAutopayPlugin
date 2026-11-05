<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Payment;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Api\AutopayClientInterface;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Refberg\SyliusAutopayPlugin\Order\AutopayTransactionValidatorInterface;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Reads one OrderID from the Autopay API, checks it against the payment and applies its state.
 */
final readonly class PaymentSynchronizer implements PaymentSynchronizerInterface
{
    public function __construct(
        private AutopayClientInterface $client,
        private AutopayTransactionValidatorInterface $validator,
        private PaymentStateApplierInterface $stateApplier,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function synchronize(PaymentInterface $payment, string $orderId, AutopayCredentials $credentials): string
    {
        $transaction = $this->client->retrieveTransaction($credentials, $orderId);

        $errors = $this->validator->validate($transaction, $payment, $credentials);
        if ([] !== $errors) {
            $this->logger->error('Autopay transaction does not match the Sylius payment; state not changed.', [
                'payment_id' => $payment->getId(),
                'order_id' => $orderId,
                'errors' => $errors,
            ]);

            return self::RESULT_INVALID;
        }

        return $this->stateApplier->apply($payment, $transaction);
    }
}
