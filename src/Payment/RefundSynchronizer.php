<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Payment;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Api\AutopayClientInterface;
use Refberg\SyliusAutopayPlugin\Api\Model\RefundCreated;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Autopay does not notify about refunds, so their status is read from the API (see the refresh-refunds command).
 *
 * The Sylius payment is already "refunded": a canceled refund is only recorded and logged, the money
 * has to be returned manually.
 */
final readonly class RefundSynchronizer implements RefundSynchronizerInterface
{
    public function __construct(
        private AutopayClientInterface $client,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function synchronize(PaymentInterface $payment, AutopayCredentials $credentials): int
    {
        $details = PaymentDetails::of($payment);
        $changed = 0;

        foreach ($details->getPendingRefundIds() as $messageId) {
            $status = $this->client->retrieveRefundStatus($credentials, $messageId);
            if (!$details->updateRefundStatus($messageId, $status)) {
                continue;
            }

            ++$changed;
            if (RefundCreated::STATUS_CANCELED === $status) {
                $this->logger->error('Autopay canceled a refund; it has to be handled manually.', [
                    'payment_id' => $payment->getId(),
                    'message_id' => $messageId,
                ]);
            }
        }

        $details->save();

        return $changed;
    }
}
