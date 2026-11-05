<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Payment;

use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayException;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Sylius\Component\Payment\Model\PaymentInterface;

interface TransactionCancellerInterface
{
    /** Nothing of the OrderID can be paid any more. */
    public const RESULT_CANCELLED = 'cancelled';

    /** Nothing was waiting for a payment and nothing was paid. */
    public const RESULT_NOTHING_PENDING = 'nothing_pending';

    /** An attempt of the OrderID was already paid. */
    public const RESULT_PAID = 'paid';

    /**
     * Cancels the attempts of an OrderID that wait for a payment (transactionCancel) and records the outcome
     * in the payment details. The same $messageId is never processed twice by Autopay.
     *
     * @throws AutopayException
     *
     * @return string one of self::RESULT_*
     */
    public function cancel(PaymentInterface $payment, string $orderId, string $messageId, AutopayCredentials $credentials): string;
}
