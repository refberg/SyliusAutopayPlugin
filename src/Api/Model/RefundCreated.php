<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Api\Model;

/**
 * Refund statuses stored by the plugin. Autopay statuses are mapped to them by refundStatus():
 * DONE stays DONE, CANCELED_* becomes CANCELED, everything else (NEW, PROCESSING, ...) is PENDING.
 */
final readonly class RefundCreated
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_DONE = 'DONE';

    public const STATUS_CANCELED = 'CANCELED';

    public function __construct(
        public string $messageId,
        public string $status = self::STATUS_PENDING,
    ) {
    }

    public static function isFinalStatus(?string $status): bool
    {
        return self::STATUS_DONE === $status || self::STATUS_CANCELED === $status;
    }
}
