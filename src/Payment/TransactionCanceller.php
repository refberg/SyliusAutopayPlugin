<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Payment;

use Refberg\SyliusAutopayPlugin\Api\AutopayClientInterface;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayApiException;
use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Refberg\SyliusAutopayPlugin\Api\Model\TransactionCancelled;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Sylius\Component\Payment\Model\PaymentInterface;
use function sprintf;

final readonly class TransactionCanceller implements TransactionCancellerInterface
{
    public function __construct(private AutopayClientInterface $client)
    {
    }

    public function cancel(PaymentInterface $payment, string $orderId, string $messageId, AutopayCredentials $credentials): string
    {
        $result = $this->client->cancelTransaction($credentials, $orderId, $messageId);

        $outcome = match (true) {
            $result->confirmed && TransactionCancelled::CANCELED_FULLY === $result->reason,
            !$result->confirmed && TransactionCancelled::TRANSACTION_NOT_FOUND === $result->reason => self::RESULT_CANCELLED,
            $result->confirmed && TransactionCancelled::CANCELED_PARTIALLY === $result->reason => self::RESULT_PAID,
            // Nothing was waiting: either every attempt failed or one was paid.
            !$result->confirmed && TransactionCancelled::INCORRECT_PAYMENT_STATUS === $result->reason => AutopayTransaction::STATUS_SUCCESS === $this->client->retrieveTransaction($credentials, $orderId)->status ? self::RESULT_PAID : self::RESULT_NOTHING_PENDING,
            default => throw new AutopayApiException(sprintf('Autopay did not cancel transaction %s (%s).', $orderId, $result->reason ?? 'no reason'), 200, $result->reason),
        };

        if (self::RESULT_CANCELLED === $outcome) {
            $details = PaymentDetails::of($payment);
            $details->recordOrderStatus($orderId, AutopayTransaction::STATUS_FAILURE);
            $details->save();
        }

        return $outcome;
    }
}
