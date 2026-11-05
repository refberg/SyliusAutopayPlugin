<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Payment;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Api\AutopayClientInterface;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayApiException;
use Refberg\SyliusAutopayPlugin\Api\Model\RefundCreated;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Reads the status of refunds in progress from the API (the refresh-refunds command), for services without ISTN
 * and as a fallback when an ISTN is missing. Refunds that never reached Autopay are requested again.
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

        try {
            foreach ($details->getPendingRefundIds() as $messageId) {
                $status = $this->status($details, $messageId, $credentials);
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
        } finally {
            $details->save();
        }

        return $changed;
    }

    /**
     * A refund still REQUESTING never reached Autopay for sure: it is requested again with the same MessageID.
     */
    private function status(PaymentDetails $details, string $messageId, AutopayCredentials $credentials): string
    {
        $refund = $details->getRefund($messageId);
        if (null === $refund || RefundCreated::STATUS_REQUESTING !== $refund['status']) {
            return $this->client->retrieveRefundStatus($credentials, $messageId);
        }

        try {
            return $this->client->createRefund($credentials, $refund['remote_id'], $refund['amount'], $refund['currency'], $messageId)->status;
        } catch (AutopayApiException $exception) {
            if ($exception->isTransient()) {
                throw $exception;
            }

            return RefundCreated::STATUS_CANCELED;
        }
    }
}
