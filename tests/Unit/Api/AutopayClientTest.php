<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\AutopayClient;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayApiException;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayTransportException;
use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Refberg\SyliusAutopayPlugin\Api\Model\RefundCreated;
use Refberg\SyliusAutopayPlugin\Api\Model\TransactionCancelled;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifier;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Refberg\SyliusAutopayPlugin\Support\Credentials;
use Tests\Refberg\SyliusAutopayPlugin\Support\FakeAutopay;
use Tests\Refberg\SyliusAutopayPlugin\Support\MemoryLogger;
use function json_encode;
use function parse_str;
use function sprintf;
use function str_repeat;

final class AutopayClientTest extends TestCase
{
    private FakeAutopay $autopay;

    private MemoryLogger $logger;

    private AutopayClient $client;

    protected function setUp(): void
    {
        $this->autopay = new FakeAutopay();
        $this->logger = new MemoryLogger();
        $this->client = new AutopayClient(new MockHttpClient($this->autopay), new SignatureVerifier(), $this->logger);
    }

    public function test_it_starts_a_transaction_in_the_background(): void
    {
        $started = $this->client->startTransaction(Credentials::fake(), self::transaction('ORDER-1'));

        self::assertSame('ORDER-1', $started->orderId);
        self::assertSame('PENDING', $started->status);
        self::assertSame('https://testpay.autopay.eu/payment/continue/' . $started->remoteId . '/ABCDEFGH', $started->redirectUrl);

        $request = $this->autopay->requests('POST', '#^/payment$#')[0];
        self::assertSame('pay-bm-continue-transaction-url', $request['headers']['bmheader']);
        parse_str($request['body'], $sent);
        self::assertSame(FakeAutopay::sign(FakeAutopay::SERVICE_ID, 'ORDER-1', '15.00', 'Order 1', 'PLN', 'buyer@example.com'), $sent['Hash'] ?? null);
        foreach ($this->logger->records as $record) {
            self::assertStringNotContainsString(FakeAutopay::SHARED_KEY, (string) json_encode($record));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forgedStartResponses(): iterable
    {
        $url = 'https://testpay.autopay.eu/payment/continue/R1/X';
        yield 'wrong hash' => [sprintf('<transaction><status>PENDING</status><redirecturl>%s</redirecturl><orderID>ORDER-1</orderID><remoteID>R1</remoteID><hash>%s</hash></transaction>', $url, str_repeat('0', 64))];
        yield 'other order' => [sprintf('<transaction><status>PENDING</status><redirecturl>%s</redirecturl><orderID>ORDER-2</orderID><remoteID>R1</remoteID><hash>%s</hash></transaction>', $url, FakeAutopay::sign('PENDING', $url, 'ORDER-2', 'R1'))];
        yield 'http redirect' => [sprintf('<transaction><status>PENDING</status><redirecturl>http://evil.example</redirecturl><orderID>ORDER-1</orderID><remoteID>R1</remoteID><hash>%s</hash></transaction>', FakeAutopay::sign('PENDING', 'http://evil.example', 'ORDER-1', 'R1'))];
        yield 'no remote id' => [sprintf('<transaction><status>PENDING</status><redirecturl>%s</redirecturl><orderID>ORDER-1</orderID><hash>%s</hash></transaction>', $url, FakeAutopay::sign('PENDING', $url, 'ORDER-1'))];
        yield 'paywall html' => ['<html><!-- PAYWAY FORM BEGIN --></html>'];
    }

    #[DataProvider('forgedStartResponses')]
    public function test_invalid_start_response_is_rejected(string $body): void
    {
        $client = new AutopayClient(new MockHttpClient(new MockResponse($body)), new SignatureVerifier());

        $this->expectException(AutopayApiException::class);

        $client->startTransaction(Credentials::fake(), self::transaction('ORDER-1'));
    }

    public function test_error_document_becomes_an_api_exception(): void
    {
        $this->autopay->failNextRequest(200, 'INVALID_HASH');

        try {
            $this->client->startTransaction(Credentials::fake(), self::transaction('ORDER-1'));
            self::fail('Expected an exception.');
        } catch (AutopayApiException $exception) {
            self::assertSame('INVALID_HASH', $exception->getErrorName());
            self::assertSame(200, $exception->getHttpStatusCode());
            self::assertTrue($exception->isAuthorizationError());
            self::assertFalse($exception->isTransient());
            self::assertStringContainsString('INVALID_HASH', $exception->getMessage());
        }
    }

    #[TestWith([500, true, false])]
    #[TestWith([503, true, false])]
    #[TestWith([408, true, false])]
    #[TestWith([429, true, false])]
    #[TestWith([401, false, true])]
    #[TestWith([403, false, true])]
    #[TestWith([400, false, false])]
    public function test_http_errors_are_classified(int $status, bool $transient, bool $authorization): void
    {
        $client = new AutopayClient(new MockHttpClient(new MockResponse('ERROR', ['http_code' => $status])), new SignatureVerifier());

        try {
            $client->retrieveTransaction(Credentials::fake(), 'ORDER-1');
            self::fail('Expected an exception.');
        } catch (AutopayApiException $exception) {
            self::assertSame($status, $exception->getHttpStatusCode());
            self::assertSame($transient, $exception->isTransient());
            self::assertSame($authorization, $exception->isAuthorizationError());
        }
    }

    public function test_error_name_decides_over_the_http_status(): void
    {
        $body = '<transaction><reason>LIMIT_REQUESTED_TRANSACTIONS_WITH_THE_SAME_ORDER_ID_AND_SERVICE_ID_EXCEEDED</reason><description>limit</description></transaction>';
        $client = new AutopayClient(new MockHttpClient(new MockResponse($body, ['http_code' => 403])), new SignatureVerifier());

        try {
            $client->retrieveTransaction(Credentials::fake(), 'ORDER-1');
            self::fail('Expected an exception.');
        } catch (AutopayApiException $exception) {
            self::assertSame('LIMIT_REQUESTED_TRANSACTIONS_WITH_THE_SAME_ORDER_ID_AND_SERVICE_ID_EXCEEDED', $exception->getErrorName());
            self::assertFalse($exception->isAuthorizationError());
            self::assertFalse($exception->isTransient());
        }
    }

    public function test_refused_transaction_start_is_an_api_exception_with_the_reason(): void
    {
        $body = '<transaction><orderID>ORDER-1</orderID><confirmation>NOTCONFIRMED</confirmation><reason>INVALID_HASH</reason></transaction>';
        $client = new AutopayClient(new MockHttpClient(new MockResponse($body)), new SignatureVerifier());

        try {
            $client->startTransaction(Credentials::fake(), self::transaction('ORDER-1'));
            self::fail('Expected an exception.');
        } catch (AutopayApiException $exception) {
            self::assertSame('INVALID_HASH', $exception->getErrorName());
            self::assertTrue($exception->isAuthorizationError());
        }
    }

    public function test_transport_error_is_a_transport_exception(): void
    {
        $client = new AutopayClient(new MockHttpClient(static fn (): never => throw new TransportException('timeout')), new SignatureVerifier());

        $this->expectException(AutopayTransportException::class);

        $client->retrieveTransaction(Credentials::fake(), 'ORDER-1');
    }

    public function test_it_reads_the_transaction_state(): void
    {
        $started = $this->client->startTransaction(Credentials::fake(), self::transaction('ORDER-1'));

        $transaction = $this->client->retrieveTransaction(Credentials::fake(), 'ORDER-1');

        self::assertEquals(new AutopayTransaction('ORDER-1', $started->remoteId, 'PENDING', 1500, 'PLN', FakeAutopay::SERVICE_ID), $transaction);
        parse_str($this->autopay->requests('POST', '#^/webapi/transactionStatus$#')[0]['body'], $sent);
        self::assertSame(['ServiceID' => FakeAutopay::SERVICE_ID, 'OrderID' => 'ORDER-1', 'Hash' => FakeAutopay::sign(FakeAutopay::SERVICE_ID, 'ORDER-1')], $sent);
    }

    public function test_a_successful_attempt_decides_the_state_of_the_order_id(): void
    {
        $this->client->startTransaction(Credentials::fake(), self::transaction('ORDER-1'));
        $this->autopay->setStatus('ORDER-1', 'FAILURE');
        $success = $this->autopay->addAttempt('ORDER-1', 'SUCCESS');
        $this->autopay->addAttempt('ORDER-1', 'PENDING');

        $transaction = $this->client->retrieveTransaction(Credentials::fake(), 'ORDER-1');

        self::assertSame('SUCCESS', $transaction->status);
        self::assertSame($success, $transaction->remoteId);
    }

    public function test_a_pending_attempt_wins_over_failed_ones(): void
    {
        $this->client->startTransaction(Credentials::fake(), self::transaction('ORDER-1'));
        $this->autopay->setStatus('ORDER-1', 'FAILURE');
        $pending = $this->autopay->addAttempt('ORDER-1', 'PENDING');
        $this->autopay->addAttempt('ORDER-1', 'FAILURE');

        $transaction = $this->client->retrieveTransaction(Credentials::fake(), 'ORDER-1');

        self::assertSame('PENDING', $transaction->status);
        self::assertSame($pending, $transaction->remoteId);
    }

    public function test_order_id_with_only_failed_attempts_failed(): void
    {
        $this->client->startTransaction(Credentials::fake(), self::transaction('ORDER-1'));
        $this->autopay->setStatus('ORDER-1', 'FAILURE');
        $last = $this->autopay->addAttempt('ORDER-1', 'FAILURE');

        $transaction = $this->client->retrieveTransaction(Credentials::fake(), 'ORDER-1');

        self::assertSame('FAILURE', $transaction->status);
        self::assertSame($last, $transaction->remoteId);
    }

    public function test_transactions_of_other_order_ids_are_ignored(): void
    {
        $xml = FakeAutopay::signedTransactionList([['orderID' => 'OTHER', 'remoteID' => 'R', 'amount' => '15.00', 'currency' => 'PLN', 'paymentStatus' => 'SUCCESS']]);
        $client = new AutopayClient(new MockHttpClient(new MockResponse($xml)), new SignatureVerifier());

        try {
            $client->retrieveTransaction(Credentials::fake(), 'ORDER-1');
            self::fail('Expected an exception.');
        } catch (AutopayApiException $exception) {
            self::assertSame(404, $exception->getHttpStatusCode());
            self::assertFalse($exception->isTransient());
        }
    }

    public function test_transaction_state_with_a_wrong_hash_is_rejected(): void
    {
        $attempt = ['orderID' => 'ORDER-1', 'remoteID' => 'R', 'amount' => '15.00', 'currency' => 'PLN', 'paymentStatus' => 'SUCCESS'];
        $client = new AutopayClient(new MockHttpClient([
            new MockResponse(FakeAutopay::transactionListXml(FakeAutopay::SERVICE_ID, [$attempt], null)),
            new MockResponse(FakeAutopay::transactionListXml(FakeAutopay::SERVICE_ID, [$attempt], FakeAutopay::sign(FakeAutopay::SERVICE_ID, 'ORDER-1', 'R', '15.00', 'PLN', 'PENDING'))),
        ]), new SignatureVerifier());

        foreach ([1, 2] as $attemptNumber) {
            try {
                $client->retrieveTransaction(Credentials::fake(), 'ORDER-1');
                self::fail('Expected an exception for response ' . $attemptNumber . '.');
            } catch (AutopayApiException $exception) {
                self::assertTrue($exception->isTransient());
            }
        }
    }

    public function test_order_id_paid_twice_reports_every_paid_attempt(): void
    {
        $started = $this->client->startTransaction(Credentials::fake(), self::transaction('ORDER-1'));
        $this->autopay->setStatus('ORDER-1', 'SUCCESS');
        $second = $this->autopay->addAttempt('ORDER-1', 'SUCCESS');

        $transaction = $this->client->retrieveTransaction(Credentials::fake(), 'ORDER-1');

        self::assertSame('SUCCESS', $transaction->status);
        self::assertSame($started->remoteId, $transaction->remoteId);
        self::assertSame([$started->remoteId, $second], $transaction->paidRemoteIds);
    }

    public function test_it_cancels_the_pending_attempts_of_an_order_id(): void
    {
        $this->client->startTransaction(Credentials::fake(), self::transaction('ORDER-1'));
        $messageId = str_repeat('d', 32);

        $result = $this->client->cancelTransaction(Credentials::fake(), 'ORDER-1', $messageId);

        self::assertEquals(new TransactionCancelled(true, TransactionCancelled::CANCELED_FULLY), $result);
        self::assertSame('FAILURE', $this->autopay->transaction('ORDER-1')['attempts'][0]['paymentStatus']);
        parse_str($this->autopay->requests('POST', '#transactionCancel$#')[0]['body'], $sent);
        self::assertSame(['ServiceID' => FakeAutopay::SERVICE_ID, 'MessageID' => $messageId, 'OrderID' => 'ORDER-1', 'Hash' => FakeAutopay::sign(FakeAutopay::SERVICE_ID, $messageId, 'ORDER-1')], $sent);

        self::assertEquals(new TransactionCancelled(false, TransactionCancelled::INCORRECT_PAYMENT_STATUS), $this->client->cancelTransaction(Credentials::fake(), 'ORDER-1', str_repeat('e', 32)));
        self::assertEquals(new TransactionCancelled(false, TransactionCancelled::TRANSACTION_NOT_FOUND), $this->client->cancelTransaction(Credentials::fake(), 'ORDER-2', str_repeat('f', 32)));
    }

    public function test_confirmed_cancellation_with_a_wrong_hash_is_rejected(): void
    {
        $body = '<transaction><serviceID>100001</serviceID><messageID>' . str_repeat('d', 32) . '</messageID><confirmation>CONFIRMED</confirmation><reason>CANCELED_FULLY</reason><hash>' . str_repeat('0', 64) . '</hash></transaction>';
        $client = new AutopayClient(new MockHttpClient(new MockResponse($body)), new SignatureVerifier());

        $this->expectException(AutopayApiException::class);

        $client->cancelTransaction(Credentials::fake(), 'ORDER-1', str_repeat('d', 32));
    }

    public function test_refund_status_with_a_wrong_hash_or_an_error_is_rejected(): void
    {
        $client = new AutopayClient(new MockHttpClient([
            new MockResponse((string) json_encode(['serviceId' => 100001, 'messageId' => str_repeat('a', 32), 'status' => 'DONE', 'remoteOutId' => 'X', 'hash' => str_repeat('0', 64)])),
            new MockResponse((string) json_encode(['serviceId' => 100001, 'messageId' => str_repeat('a', 32), 'status' => null, 'errorStatus' => 'NOT_FOUND'])),
        ]), new SignatureVerifier());

        foreach ([1, 2] as $attemptNumber) {
            try {
                $client->retrieveRefundStatus(Credentials::fake(), str_repeat('a', 32));
                self::fail('Expected an exception for response ' . $attemptNumber . '.');
            } catch (AutopayApiException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_incomplete_transaction_state_is_rejected(): void
    {
        $xml = FakeAutopay::signedTransactionList([['orderID' => 'ORDER-1', 'remoteID' => 'R', 'amount' => 'abc', 'currency' => 'PLN', 'paymentStatus' => 'SUCCESS']]);
        $client = new AutopayClient(new MockHttpClient(new MockResponse($xml)), new SignatureVerifier());

        $this->expectException(AutopayApiException::class);

        $client->retrieveTransaction(Credentials::fake(), 'ORDER-1');
    }

    public function test_it_requests_a_refund_once_per_message_id(): void
    {
        $this->client->startTransaction(Credentials::fake(), self::transaction('ORDER-1'));
        $this->autopay->setStatus('ORDER-1', 'SUCCESS');
        $remoteId = $this->autopay->transaction('ORDER-1')['attempts'][0]['remoteID'];
        $messageId = str_repeat('a', 32);

        $first = $this->client->createRefund(Credentials::fake(), $remoteId, 1500, 'PLN', $messageId);
        $second = $this->client->createRefund(Credentials::fake(), $remoteId, 1500, 'PLN', $messageId);

        self::assertEquals(new RefundCreated($messageId, RefundCreated::STATUS_PENDING), $first);
        self::assertEquals($first, $second);
        self::assertCount(1, $this->autopay->refunds());
        parse_str($this->autopay->requests('POST', '#transactionRefund$#')[0]['body'], $sent);
        self::assertSame(FakeAutopay::sign(FakeAutopay::SERVICE_ID, $messageId, $remoteId, '15.00', 'PLN'), $sent['Hash'] ?? null);
    }

    public function test_refund_confirmation_with_a_wrong_hash_is_rejected(): void
    {
        $body = '<refund><serviceID>100001</serviceID><messageID>' . str_repeat('a', 32) . '</messageID><hash>' . str_repeat('0', 64) . '</hash></refund>';
        $client = new AutopayClient(new MockHttpClient(new MockResponse($body)), new SignatureVerifier());

        $this->expectException(AutopayApiException::class);

        $client->createRefund(Credentials::fake(), 'R1', 1500, 'PLN', str_repeat('a', 32));
    }

    #[TestWith(['NEW', RefundCreated::STATUS_PENDING])]
    #[TestWith(['PROCESSING', RefundCreated::STATUS_PENDING])]
    #[TestWith(['DONE', RefundCreated::STATUS_DONE])]
    #[TestWith(['CANCELED_NO_FUNDS_ON_BALANCE', RefundCreated::STATUS_CANCELED])]
    #[TestWith(['CANCELED_MANUALLY_BY_SERVICES', RefundCreated::STATUS_CANCELED])]
    public function test_refund_status_is_mapped(string $autopayStatus, string $status): void
    {
        $this->client->startTransaction(Credentials::fake(), self::transaction('ORDER-1'));
        $this->autopay->setStatus('ORDER-1', 'SUCCESS');
        $messageId = str_repeat('b', 32);
        $this->client->createRefund(Credentials::fake(), $this->autopay->transaction('ORDER-1')['attempts'][0]['remoteID'], 100, 'PLN', $messageId);
        $this->autopay->setRefundStatus($messageId, $autopayStatus);

        self::assertSame($status, $this->client->retrieveRefundStatus(Credentials::fake(), $messageId));
    }

    public function test_unknown_refund_status_is_an_api_exception(): void
    {
        $this->expectException(AutopayApiException::class);

        $this->client->retrieveRefundStatus(Credentials::fake(), str_repeat('c', 32));
    }

    /**
     * @return array<string, string>
     */
    private static function transaction(string $orderId): array
    {
        return [
            'OrderID' => $orderId,
            'Amount' => '15.00',
            'Description' => 'Order 1',
            'Currency' => 'PLN',
            'CustomerEmail' => 'buyer@example.com',
        ];
    }
}
