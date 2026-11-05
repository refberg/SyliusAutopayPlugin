<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Notification;

/**
 * An Autopay ITN (Instant Transaction Notification) whose hash has been verified.
 *
 * Only the identifiers are used to change the payment: the transaction state itself
 * is always read again from the Autopay API.
 */
final readonly class Notification
{
    public const TYPE_TRANSACTION = 'transaction';

    public function __construct(
        public string $type,
        public string $serviceId,
        public string $orderId,
        public ?string $remoteId = null,
        public ?string $status = null,
    ) {
    }
}
