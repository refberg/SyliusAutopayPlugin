<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Api\Model;

/**
 * Answer to a transaction started in the background ("pay-bm-continue-transaction-url").
 */
final readonly class TransactionStarted
{
    public function __construct(
        public string $orderId,
        public string $remoteId,
        public string $redirectUrl,
        public string $status,
    ) {
    }
}
