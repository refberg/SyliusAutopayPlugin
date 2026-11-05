<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Notification;

/**
 * An Autopay notification whose hash has been verified:
 *  - ITN (TYPE_TRANSACTION): change of an incoming transaction. Only the identifiers are used;
 *    the transaction state itself is always read again from the Autopay API.
 *  - ISTN (TYPE_REFUND, TYPE_SETTLEMENT): change of an outgoing transfer, sent only when Autopay enabled ISTN
 *    for the service. Refunds requested by Sylius have the MessageID in $refundId (orderOutID); refunds made
 *    in the Autopay panel have an ID given by Autopay.
 */
final readonly class Notification
{
    public const TYPE_TRANSACTION = 'transaction';

    public const TYPE_REFUND = 'refund';

    public const TYPE_SETTLEMENT = 'settlement';

    public function __construct(
        public string $type,
        public string $serviceId,
        public ?string $orderId,
        public ?string $remoteId = null,
        public ?string $status = null,
        public ?string $refundId = null,
        public ?string $remoteOutId = null,
        public ?int $amount = null,
        public ?string $currency = null,
    ) {
    }

    public function isTransfer(): bool
    {
        return self::TYPE_TRANSACTION !== $this->type;
    }
}
