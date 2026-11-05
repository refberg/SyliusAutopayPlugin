<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Api;

use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayException;
use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Refberg\SyliusAutopayPlugin\Api\Model\RefundCreated;
use Refberg\SyliusAutopayPlugin\Api\Model\TransactionStarted;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;

interface AutopayClientInterface
{
    /**
     * Starts a transaction in the background and returns the Autopay page to redirect the customer to.
     *
     * @param array<string, string> $parameters transaction parameters without ServiceID and Hash, in the order defined by Autopay
     *
     * @throws AutopayException
     */
    public function startTransaction(AutopayCredentials $credentials, array $parameters): TransactionStarted;

    /**
     * @throws AutopayException
     */
    public function retrieveTransaction(AutopayCredentials $credentials, string $orderId): AutopayTransaction;

    /**
     * Requests a refund of a paid transaction. The same $messageId never creates a second refund.
     *
     * @throws AutopayException
     */
    public function createRefund(
        AutopayCredentials $credentials,
        string $remoteId,
        int $amount,
        string $currency,
        string $messageId,
    ): RefundCreated;

    /**
     * @throws AutopayException
     *
     * @return string one of RefundCreated::STATUS_*
     */
    public function retrieveRefundStatus(AutopayCredentials $credentials, string $messageId): string;
}
