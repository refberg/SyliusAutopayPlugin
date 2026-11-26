<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\CommandHandler;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayApiException;
use Refberg\SyliusAutopayPlugin\Command\StatusPaymentRequest;
use Refberg\SyliusAutopayPlugin\CommandHandler\StatusPaymentRequestHandler;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolver;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusAutopayPlugin\Payment\TransactionCancellerInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Tests\Refberg\SyliusAutopayPlugin\Support\FakeAutopay;
use Tests\Refberg\SyliusAutopayPlugin\Support\HandlerFixtures;
use Tests\Refberg\SyliusAutopayPlugin\Support\MemoryLogger;
use Tests\Refberg\SyliusAutopayPlugin\Support\PaymentStateMachine;
use function array_filter;
use function array_values;
use function in_array;

final class StatusPaymentRequestHandlerTest extends TestCase
{
    #[TestWith(['SUCCESS', PaymentRequestInterface::STATE_COMPLETED])]
    #[TestWith(['FAILURE', PaymentRequestInterface::STATE_PROCESSING])]
    #[TestWith(['PENDING', PaymentRequestInterface::STATE_PROCESSING])]
    public function test_capture_request_of_the_checked_order_id_is_completed_once_it_is_paid(string $autopayStatus, string $expectedState): void
    {
        $method = HandlerFixtures::method();
        $payment = self::payment($method);

        $capture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 'O1');
        $otherOrderCapture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 'O2');
        $cancelledCapture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_CANCELLED, 'O1');
        $statusRequest = HandlerFixtures::paymentRequest($payment, $method, PaymentRequestInterface::ACTION_STATUS);
        $requests = [$capture, $otherOrderCapture, $cancelledCapture, $statusRequest];

        $this->handle($statusRequest, $requests, $autopayStatus);

        self::assertSame($expectedState, $capture->getState());
        self::assertSame(['redirect_url' => 'https://' . FakeAutopay::HOST . '/payment/continue/R-O1', 'order_id' => 'O1', 'remote_id' => 'R-O1'], $capture->getResponseData());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $otherOrderCapture->getState());
        self::assertSame(PaymentRequestInterface::STATE_CANCELLED, $cancelledCapture->getState());
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $statusRequest->getState());
    }

    #[TestWith(['SUCCESS', PaymentRequestInterface::STATE_COMPLETED])]
    #[TestWith(['FAILURE', PaymentRequestInterface::STATE_PROCESSING])]
    public function test_capture_request_left_processing_for_an_already_paid_order_id_is_completed(string $autopayStatus, string $expectedState): void
    {
        $method = HandlerFixtures::method();
        $payment = self::payment($method);
        $details = PaymentDetails::of($payment);
        $details->recordOrderStatus('O1', $autopayStatus);
        $details->save();

        $capture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 'O1');
        $statusRequest = HandlerFixtures::paymentRequest($payment, $method, PaymentRequestInterface::ACTION_STATUS);

        $this->handle($statusRequest, [$capture, $statusRequest], 'PENDING');

        self::assertSame($expectedState, $capture->getState());
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $statusRequest->getState());
    }

    public function test_authorize_request_is_completed_like_a_capture_and_a_request_without_order_id_is_left_alone(): void
    {
        $method = HandlerFixtures::method();
        $payment = self::payment($method);

        $authorize = self::request($payment, $method, PaymentRequestInterface::ACTION_AUTHORIZE, PaymentRequestInterface::STATE_PROCESSING, 'O1');
        $captureWithoutOrderId = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 'O1');
        $captureWithoutOrderId->setResponseData(['redirect_url' => 'https://' . FakeAutopay::HOST . '/payment/continue/R-O1']);
        $statusRequest = HandlerFixtures::paymentRequest($payment, $method, PaymentRequestInterface::ACTION_STATUS);

        $this->handle($statusRequest, [$authorize, $captureWithoutOrderId, $statusRequest], 'SUCCESS');

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $authorize->getState());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $captureWithoutOrderId->getState());
        self::assertSame(['redirect_url' => 'https://' . FakeAutopay::HOST . '/payment/continue/R-O1'], $captureWithoutOrderId->getResponseData());
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $statusRequest->getState());
    }

    public function test_api_error_fails_the_request_and_keeps_the_payment(): void
    {
        $method = HandlerFixtures::method();
        $payment = self::payment($method);
        $capture = self::request($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::STATE_PROCESSING, 'O1');
        $statusRequest = HandlerFixtures::paymentRequest($payment, $method, PaymentRequestInterface::ACTION_STATUS);
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willThrowException(new AutopayApiException('Checking Autopay transaction failed (HTTP 503).', 503));
        $logger = new MemoryLogger();

        $this->invoke($statusRequest, [$capture, $statusRequest], $synchronizer, $logger);

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $statusRequest->getState());
        self::assertSame(['error' => 'Checking Autopay transaction failed (HTTP 503).'], $statusRequest->getResponseData());
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $capture->getState());
        self::assertSame('warning', $logger->records[0]['level'] ?? null);
        self::assertSame(['payment_id' => 42, 'reason' => 'Checking Autopay transaction failed (HTTP 503).'], $logger->records[0]['context']);
    }

    /**
     * @param list<PaymentRequestInterface> $requests
     */
    private function handle(PaymentRequestInterface $statusRequest, array $requests, string $autopayStatus): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willReturnCallback(static function (PaymentInterface $synchronized, string $orderId) use ($autopayStatus): string {
            $details = PaymentDetails::of($synchronized);
            $details->recordOrderStatus($orderId, $autopayStatus);
            $details->save();

            return PaymentStateApplierInterface::RESULT_UNCHANGED;
        });

        $this->invoke($statusRequest, $requests, $synchronizer);
    }

    /**
     * @param list<PaymentRequestInterface> $requests
     */
    private function invoke(PaymentRequestInterface $statusRequest, array $requests, PaymentSynchronizerInterface $synchronizer, MemoryLogger $logger = new MemoryLogger()): void
    {
        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($statusRequest);

        $paymentRequestRepository = self::createStub(PaymentRequestRepositoryInterface::class);
        $paymentRequestRepository->method('findByPaymentIdAndStates')->willReturnCallback(
            static fn (mixed $paymentId, array $states): array => array_values(array_filter($requests, static fn (PaymentRequestInterface $request): bool => in_array($request->getState(), $states, true))),
        );

        $handler = new StatusPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            $synchronizer,
            self::createStub(TransactionCancellerInterface::class),
            self::createStub(PaymentLockerInterface::class),
            new PaymentStateMachine(),
            $logger,
            $paymentRequestRepository,
        );

        $handler(new StatusPaymentRequest(HandlerFixtures::HASH));
    }

    private static function payment(PaymentMethod $method): Payment
    {
        $payment = HandlerFixtures::payment($method, PaymentInterface::STATE_NEW);
        $details = PaymentDetails::of($payment);
        $details->addOrder('O1', FakeAutopay::SERVICE_ID, 'R-O1', 1500, 'PLN');
        $details->save();

        return $payment;
    }

    private static function request(PaymentInterface $payment, PaymentMethod $method, string $action, string $state, string $orderId): PaymentRequestInterface
    {
        $request = new PaymentRequest($payment, $method);
        $request->setAction($action);
        $request->setState($state);
        $request->setResponseData(['redirect_url' => 'https://' . FakeAutopay::HOST . '/payment/continue/R-' . $orderId, 'order_id' => $orderId, 'remote_id' => 'R-' . $orderId]);

        return $request;
    }
}
