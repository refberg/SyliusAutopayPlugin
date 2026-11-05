<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Api\Model;

/**
 * Answer to transactionCancel for an OrderID. After a successful cancellation Autopay sends an ITN with FAILURE.
 */
final readonly class TransactionCancelled
{
    /** All attempts waiting for a payment were cancelled. */
    public const CANCELED_FULLY = 'CANCELED_FULLY';

    /** Some attempts were cancelled, others could not be (e.g. they were already paid). */
    public const CANCELED_PARTIALLY = 'CANCELED_PARTIALLY';

    /** No attempt was waiting for a payment (e.g. it was already paid or failed). */
    public const INCORRECT_PAYMENT_STATUS = 'INCORRECT_PAYMENT_STATUS';

    public const TRANSACTION_NOT_FOUND = 'TRANSACTION_NOT_FOUND';

    public function __construct(
        public bool $confirmed,
        public ?string $reason,
    ) {
    }
}
