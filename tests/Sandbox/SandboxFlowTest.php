<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Sandbox;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\AutopayClient;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayApiException;
use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Refberg\SyliusAutopayPlugin\Api\Model\RefundCreated;
use Refberg\SyliusAutopayPlugin\Api\Model\TransactionCancelled;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifier;
use Symfony\Component\HttpClient\HttpClient;
use Tests\Refberg\SyliusAutopayPlugin\Support\SandboxEnvironment;
use Tests\Refberg\SyliusAutopayPlugin\Support\SandboxOnlyHttpClient;
use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * Checks the assumptions of docs/autopay.md against the real Autopay test environment (testpay.autopay.eu).
 *
 * Skipped unless AUTOPAY_SANDBOX=1, AUTOPAY_SANDBOX_SERVICE_ID and AUTOPAY_SANDBOX_SHARED_KEY are set.
 * Not part of CI.
 */
final class SandboxFlowTest extends TestCase
{
    private AutopayClient $client;

    private AutopayCredentials $credentials;

    protected function setUp(): void
    {
        $credentials = SandboxEnvironment::credentials();
        if (!SandboxEnvironment::isEnabled() || !$credentials instanceof AutopayCredentials) {
            self::markTestSkipped('Set AUTOPAY_SANDBOX=1, AUTOPAY_SANDBOX_SERVICE_ID and AUTOPAY_SANDBOX_SHARED_KEY to run tests against the Autopay test environment.');
        }

        $this->credentials = $credentials;
        $this->client = new AutopayClient(new SandboxOnlyHttpClient(HttpClient::create()), new SignatureVerifier());
    }

    /**
     * Background start with ReturnURL in the hash, and the signed transaction status answer.
     */
    public function test_transaction_is_started_and_its_status_can_be_read(): void
    {
        $orderId = sprintf('sandbox-%s', bin2hex(random_bytes(8)));

        $started = $this->client->startTransaction($this->credentials, [
            'OrderID' => $orderId,
            'Amount' => '1.23',
            'Description' => 'Sylius plugin sandbox test',
            'Currency' => 'PLN',
            'CustomerEmail' => 'sandbox@example.com',
            'ReturnURL' => 'https://shop.example/after-pay',
        ]);

        self::assertSame($orderId, $started->orderId);
        self::assertStringStartsWith('https://', $started->redirectUrl);

        $transaction = $this->client->retrieveTransaction($this->credentials, $orderId);
        self::assertSame(AutopayTransaction::STATUS_PENDING, $transaction->status);
        self::assertSame(123, $transaction->amount);
        self::assertSame('PLN', $transaction->currency);
    }

    /**
     * transactionCancel of a started transaction, then its status.
     */
    public function test_started_transaction_can_be_cancelled(): void
    {
        $orderId = sprintf('sandbox-%s', bin2hex(random_bytes(8)));
        $this->client->startTransaction($this->credentials, ['OrderID' => $orderId, 'Amount' => '1.00', 'Currency' => 'PLN', 'CustomerEmail' => 'sandbox@example.com']);

        $result = $this->client->cancelTransaction($this->credentials, $orderId, bin2hex(random_bytes(16)));

        self::assertTrue($result->confirmed, (string) $result->reason);
        self::assertSame(TransactionCancelled::CANCELED_FULLY, $result->reason);
    }

    /**
     * A1: a request signed with a wrong key is an authorization error.
     */
    public function test_wrong_shared_key_is_an_authorization_error(): void
    {
        $credentials = new AutopayCredentials($this->credentials->serviceId, 'wrong-key', $this->credentials->hashAlgorithm, $this->credentials->environment);

        try {
            $this->client->retrieveTransaction($credentials, 'sandbox-unknown');
            self::fail('Expected an exception.');
        } catch (AutopayApiException $exception) {
            self::assertTrue($exception->isAuthorizationError(), $exception->getMessage());
        }
    }

    /**
     * Refund idempotency (MessageID) and refund status. Pay a transaction on the Autopay test page first
     * and pass its RemoteID in AUTOPAY_SANDBOX_PAID_REMOTE_ID.
     */
    public function test_refund_is_idempotent_and_its_status_can_be_read(): void
    {
        $remoteId = SandboxEnvironment::paidRemoteId();
        if (null === $remoteId) {
            self::markTestSkipped('Set AUTOPAY_SANDBOX_PAID_REMOTE_ID to a paid test transaction.');
        }

        $messageId = bin2hex(random_bytes(16));
        $first = $this->client->createRefund($this->credentials, $remoteId, 1, 'PLN', $messageId);
        $second = $this->client->createRefund($this->credentials, $remoteId, 1, 'PLN', $messageId);

        self::assertSame($first->messageId, $second->messageId);
        self::assertContains($this->client->retrieveRefundStatus($this->credentials, $messageId), [
            RefundCreated::STATUS_PENDING,
            RefundCreated::STATUS_DONE,
        ]);
    }
}
