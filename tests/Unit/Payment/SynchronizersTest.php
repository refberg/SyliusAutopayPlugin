<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Payment;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\AutopayClient;
use Refberg\SyliusAutopayPlugin\Api\Model\RefundCreated;
use Refberg\SyliusAutopayPlugin\Order\AutopayTransactionValidator;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentStateApplier;
use Refberg\SyliusAutopayPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentSynchronizer;
use Refberg\SyliusAutopayPlugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusAutopayPlugin\Payment\RefundSynchronizer;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifier;
use Sylius\Component\Core\Model\Payment;
use Symfony\Component\HttpClient\MockHttpClient;
use Tests\Refberg\SyliusAutopayPlugin\Support\Credentials;
use Tests\Refberg\SyliusAutopayPlugin\Support\FakeAutopay;
use Tests\Refberg\SyliusAutopayPlugin\Support\MemoryLogger;
use Tests\Refberg\SyliusAutopayPlugin\Support\PaymentStateMachine;
use function str_repeat;

final class SynchronizersTest extends TestCase
{
    private FakeAutopay $autopay;

    private AutopayClient $client;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->autopay = new FakeAutopay();
        $this->client = new AutopayClient(new MockHttpClient($this->autopay), new SignatureVerifier());
        $this->logger = new MemoryLogger();
    }

    public function test_matching_transaction_state_is_applied(): void
    {
        $payment = $this->startedPayment(1500);
        $this->autopay->setStatus('O1', 'SUCCESS');

        self::assertSame(PaymentStateApplierInterface::RESULT_COMPLETED, $this->paymentSynchronizer()->synchronize($payment, 'O1', Credentials::fake()));
        self::assertSame('completed', $payment->getState());
    }

    public function test_transaction_that_does_not_match_is_not_applied(): void
    {
        $payment = $this->startedPayment(1500);
        $payment->setAmount(1499);
        $this->autopay->setStatus('O1', 'SUCCESS');

        self::assertSame(PaymentSynchronizerInterface::RESULT_INVALID, $this->paymentSynchronizer()->synchronize($payment, 'O1', Credentials::fake()));
        self::assertSame('new', $payment->getState());
        self::assertSame('error', $this->logger->records[0]['level'] ?? null);
        self::assertSame(['amount does not match'], $this->logger->records[0]['context']['errors'] ?? null);
    }

    public function test_refund_statuses_are_read_until_final(): void
    {
        $payment = $this->startedPayment(1500);
        $this->autopay->setStatus('O1', 'SUCCESS');
        $remoteId = $this->autopay->transaction('O1')['attempts'][0]['remoteID'];
        $done = str_repeat('a', 32);
        $canceled = str_repeat('b', 32);
        $pending = str_repeat('c', 32);
        $details = PaymentDetails::of($payment);
        foreach ([$done, $canceled, $pending] as $messageId) {
            $this->client->createRefund(Credentials::fake(), $remoteId, 100, 'PLN', $messageId);
            $details->recordRefund($messageId, $remoteId, RefundCreated::STATUS_PENDING, 100, 'PLN');
        }
        $details->save();
        $this->autopay->setRefundStatus($done, 'DONE');
        $this->autopay->setRefundStatus($canceled, 'CANCELED_AMOUNT_EXCEEDS_TRANSACTION');

        $synchronizer = new RefundSynchronizer($this->client, $this->logger);

        self::assertSame(2, $synchronizer->synchronize($payment, Credentials::fake()));
        $details = PaymentDetails::of($payment);
        self::assertSame('DONE', $details->refunds()[$done]['status']);
        self::assertSame('CANCELED', $details->refunds()[$canceled]['status']);
        self::assertSame([$pending], $details->getPendingRefundIds());
        self::assertSame('error', $this->logger->records[0]['level'] ?? null);

        self::assertSame(0, $synchronizer->synchronize($payment, Credentials::fake()));
        self::assertCount(4, $this->autopay->requests('POST', '#outDetails#'));
    }

    private function paymentSynchronizer(): PaymentSynchronizer
    {
        return new PaymentSynchronizer($this->client, new AutopayTransactionValidator(), new PaymentStateApplier(new PaymentStateMachine()), $this->logger);
    }

    private function startedPayment(int $amount): Payment
    {
        $started = $this->client->startTransaction(Credentials::fake(), ['OrderID' => 'O1', 'Amount' => '15.00', 'Currency' => 'PLN']);
        $payment = new Payment();
        $payment->setAmount($amount);
        $payment->setCurrencyCode('PLN');
        $payment->setState('new');
        $details = PaymentDetails::of($payment);
        $details->addOrder('O1', FakeAutopay::SERVICE_ID, $started->remoteId, $amount, 'PLN');
        $details->save();

        return $payment;
    }
}
