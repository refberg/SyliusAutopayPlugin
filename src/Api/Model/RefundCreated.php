<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Api\Model;

/**
 * Refund statuses stored by the plugin. Autopay statuses are mapped to them by retrieveRefundStatus():
 * DONE stays DONE, CANCELED_* becomes CANCELED, everything else (NEW, PROCESSING, ...) is PENDING.
 * REQUESTING is stored before the refund is requested; it stays when Autopay could not be reached,
 * so the refund is requested again with the same MessageID.
 */
final readonly class RefundCreated
{
    public const STATUS_REQUESTING = 'REQUESTING';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_DONE = 'DONE';

    public const STATUS_CANCELED = 'CANCELED';

    public function __construct(
        public string $messageId,
        public string $status = self::STATUS_PENDING,
    ) {
    }

    /**
     * DONE and CANCELED end the refund status checks; only DONE is never changed again,
     * because Autopay can still make a canceled refund (e.g. after its support re-runs it).
     */
    public static function isFinalStatus(?string $status): bool
    {
        return self::STATUS_DONE === $status || self::STATUS_CANCELED === $status;
    }
}
