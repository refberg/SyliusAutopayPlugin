<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Api\Model;

/**
 * State of one Autopay OrderID as read from the transaction status API.
 *
 * The customer may make several attempts (remoteIDs) for one OrderID on the Autopay page;
 * the OrderID is SUCCESS when any attempt succeeded, PENDING when any is still in progress
 * and FAILURE only when all attempts failed.
 */
final readonly class AutopayTransaction
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILURE = 'FAILURE';

    public function __construct(
        public string $orderId,
        public ?string $remoteId,
        public string $status,
        public int $amount,
        public string $currency,
        public ?string $serviceId,
        /** @var list<string> RemoteIDs of all successful attempts; more than one means the OrderID was paid more than once */
        public array $paidRemoteIds = [],
    ) {
    }

    public static function isFinalStatus(?string $status): bool
    {
        return self::STATUS_SUCCESS === $status || self::STATUS_FAILURE === $status;
    }
}
