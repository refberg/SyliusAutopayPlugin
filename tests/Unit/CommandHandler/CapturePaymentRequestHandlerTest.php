<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\CommandHandler;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\AutopayClient;
use Refberg\SyliusAutopayPlugin\Command\CapturePaymentRequest;
use Refberg\SyliusAutopayPlugin\CommandHandler\CapturePaymentRequestHandler;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolver;
use Refberg\SyliusAutopayPlugin\Order\ExtOrderIdGenerator;
use Refberg\SyliusAutopayPlugin\Order\TransactionRequestFactoryInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifier;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Tests\Refberg\SyliusAutopayPlugin\Support\FakeAutopay;
use Tests\Refberg\SyliusAutopayPlugin\Support\HandlerFixtures;
use Tests\Refberg\SyliusAutopayPlugin\Support\PaymentStateMachine;
use function json_encode;

final class CapturePaymentRequestHandlerTest extends TestCase
{
    public function test_it_starts_a_transaction_and_records_it(): void
    {
        [$handler, $autopay, $paymentRequest, $payment] = $this->handler(static function (): void {
        });

        $handler(new CapturePaymentRequest(HandlerFixtures::HASH));

        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $paymentRequest->getState(), (string) json_encode($paymentRequest->getResponseData()));
        $orderId = $autopay->lastOrderId();
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $paymentRequest->getState());
        self::assertSame($orderId, $paymentRequest->getResponseData()['order_id'] ?? null);
        self::assertTrue(PaymentDetails::of($payment)->hasOrderOfService($orderId, FakeAutopay::SERVICE_ID));
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
    }

    public function test_payment_completed_while_waiting_for_the_lock_gets_no_new_transaction(): void
    {
        // An ITN completes the payment while the capture waits for the lock.
        [$handler, $autopay, $paymentRequest] = $this->handler(static function (PaymentInterface $locked): void {
            $locked->setState(PaymentInterface::STATE_COMPLETED);
        });

        $handler(new CapturePaymentRequest(HandlerFixtures::HASH));

        self::assertSame([], $autopay->requests('POST', '#^/payment$#'));
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'Payment in state "completed" cannot be paid.'], $paymentRequest->getResponseData());
    }

    public function test_request_that_is_already_processing_is_left_alone(): void
    {
        [$handler, $autopay, $paymentRequest] = $this->handler(static function (): void {
        });
        $paymentRequest->setState(PaymentRequestInterface::STATE_PROCESSING);

        $handler(new CapturePaymentRequest(HandlerFixtures::HASH));

        self::assertSame([], $autopay->requests());
    }

    /**
     * @param callable(PaymentInterface): void $onLock
     *
     * @return array{CapturePaymentRequestHandler, FakeAutopay, PaymentRequestInterface, PaymentInterface}
     */
    private function handler(callable $onLock): array
    {
        $autopay = new FakeAutopay();
        $method = HandlerFixtures::method();
        $payment = HandlerFixtures::payment($method, PaymentInterface::STATE_NEW);
        $paymentRequest = HandlerFixtures::paymentRequest($payment, $method, PaymentRequestInterface::ACTION_CAPTURE);

        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $transactionRequestFactory = self::createStub(TransactionRequestFactoryInterface::class);
        $transactionRequestFactory->method('create')->willReturnCallback(static fn (PaymentRequestInterface $request, PaymentInterface $payment, string $orderId): array => [
            'OrderID' => $orderId,
            'Amount' => '15.00',
            'Currency' => 'PLN',
        ]);

        $paymentLocker = self::createStub(PaymentLockerInterface::class);
        $paymentLocker->method('lock')->willReturnCallback($onLock);

        $handler = new CapturePaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            new AutopayClient(new MockHttpClient($autopay), new SignatureVerifier()),
            $transactionRequestFactory,
            new ExtOrderIdGenerator(),
            $paymentLocker,
            new PaymentStateMachine(),
        );

        return [$handler, $autopay, $paymentRequest, $payment];
    }
}
