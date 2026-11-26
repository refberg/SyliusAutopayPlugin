<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\CommandHandler;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayApiException;
use Refberg\SyliusAutopayPlugin\Command\NotifyPaymentRequest;
use Refberg\SyliusAutopayPlugin\CommandHandler\NotifyPaymentRequestHandler;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolver;
use Refberg\SyliusAutopayPlugin\Notification\Notification;
use Refberg\SyliusAutopayPlugin\Notification\NotificationParser;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusAutopayPlugin\Payment\TransactionCancellerInterface;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifier;
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
use function array_column;
use function array_filter;
use function array_values;
use function hash;
use function in_array;
use function substr;

final class NotifyPaymentRequestHandlerTest extends TestCase
{
    public function test_stored_itn_is_verified_again(): void
    {
        $synchronizer = self::createMock(PaymentSynchronizerInterface::class);
        $synchronizer->expects(self::never())->method('synchronize');
        [$handler, $paymentRequest] = $this->handler($synchronizer, FakeAutopay::itn(['orderID' => 'O1', 'paymentStatus' => 'SUCCESS'], 'attacker-key'));

        $handler(new NotifyPaymentRequest(HandlerFixtures::HASH));

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'Invalid notification hash or body.'], $paymentRequest->getResponseData());
    }

    public function test_order_id_of_another_payment_is_rejected(): void
    {
        $synchronizer = self::createMock(PaymentSynchronizerInterface::class);
        $synchronizer->expects(self::never())->method('synchronize');
        [$handler, $paymentRequest] = $this->handler($synchronizer, FakeAutopay::itn(['orderID' => 'OTHER', 'paymentStatus' => 'SUCCESS']));

        $handler(new NotifyPaymentRequest(HandlerFixtures::HASH));

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
    }

    #[TestWith([503, null])]
    #[TestWith([200, 'INVALID_HASH'])]
    #[TestWith([401, null])]
    public function test_errors_worth_a_retry_are_rethrown(int $httpStatus, ?string $errorName): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willThrowException(new AutopayApiException('failed', $httpStatus, $errorName));
        [$handler, $paymentRequest] = $this->handler($synchronizer, FakeAutopay::itn(['orderID' => 'O1', 'paymentStatus' => 'SUCCESS']));

        try {
            $handler(new NotifyPaymentRequest(HandlerFixtures::HASH));
            self::fail('Expected an exception.');
        } catch (AutopayApiException) {
            self::assertSame(PaymentRequestInterface::STATE_NEW, $paymentRequest->getState());
        }
    }

    public function test_permanent_error_fails_the_request(): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willThrowException(new AutopayApiException('not found', 404));
        [$handler, $paymentRequest] = $this->handler($synchronizer, FakeAutopay::itn(['orderID' => 'O1', 'paymentStatus' => 'SUCCESS']));

        $handler(new NotifyPaymentRequest(HandlerFixtures::HASH));

        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
    }

    public function test_completed_payment_gets_the_paid_method_back(): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willReturnCallback(static function (PaymentInterface $payment): string {
            $payment->setState(PaymentInterface::STATE_COMPLETED);

            return PaymentStateApplierInterface::RESULT_COMPLETED;
        });
        [$handler, $paymentRequest, $payment, $method] = $this->handler($synchronizer, FakeAutopay::itn(['orderID' => 'O1', 'paymentStatus' => 'SUCCESS']));
        $other = new PaymentMethod();
        $other->setCode('BANK_TRANSFER');
        $payment->setMethod($other);

        $handler(new NotifyPaymentRequest(HandlerFixtures::HASH));

        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $paymentRequest->getState());
        self::assertSame([
            'type' => Notification::TYPE_TRANSACTION,
            'order_id' => 'O1',
            'result' => PaymentStateApplierInterface::RESULT_COMPLETED,
            'payment_state' => PaymentInterface::STATE_COMPLETED,
        ], $paymentRequest->getResponseData());
        self::assertSame($method, $payment->getMethod());
        self::assertSame([['type' => PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, 'order_id' => 'O1']], PaymentDetails::of($payment)->warnings());
    }

    public function test_payment_that_already_has_a_method_with_the_code_that_started_the_order_id_is_not_changed(): void
    {
        [$handler, , $payment] = $this->handler(self::completingSynchronizer(), FakeAutopay::itn(['orderID' => 'O1', 'paymentStatus' => 'SUCCESS']), startedWith: 'AUTOPAY');
        $sameCode = HandlerFixtures::method();
        $payment->setMethod($sameCode);

        $handler(new NotifyPaymentRequest(HandlerFixtures::HASH));

        self::assertSame($sameCode, $payment->getMethod());
        self::assertSame([], PaymentDetails::of($payment)->warnings());
    }

    public function test_switched_method_is_restored_when_the_notified_method_started_the_order_id(): void
    {
        [$handler, , $payment, $method] = $this->handler(self::completingSynchronizer(), FakeAutopay::itn(['orderID' => 'O1', 'paymentStatus' => 'SUCCESS']), startedWith: 'AUTOPAY');
        $other = new PaymentMethod();
        $other->setCode('BANK_TRANSFER');
        $payment->setMethod($other);

        $handler(new NotifyPaymentRequest(HandlerFixtures::HASH));

        self::assertSame($method, $payment->getMethod());
        self::assertSame([['type' => PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, 'order_id' => 'O1']], PaymentDetails::of($payment)->warnings());
    }

    public function test_method_other_than_the_one_that_started_the_order_id_is_not_restored(): void
    {
        $logger = new MemoryLogger();
        [$handler, , $payment] = $this->handler(self::completingSynchronizer(), FakeAutopay::itn(['orderID' => 'O1', 'paymentStatus' => 'SUCCESS']), logger: $logger, startedWith: 'AUTOPAY_OTHER');
        $other = new PaymentMethod();
        $other->setCode('BANK_TRANSFER');
        $payment->setMethod($other);

        $handler(new NotifyPaymentRequest(HandlerFixtures::HASH));

        self::assertSame($other, $payment->getMethod());
        self::assertSame([], PaymentDetails::of($payment)->warnings());
        self::assertSame(['warning'], array_column($logger->records, 'level'));
    }

    public function test_payment_is_locked_once(): void
    {
        $paymentLocker = self::createMock(PaymentLockerInterface::class);
        $paymentLocker->expects(self::once())->method('lock');
        [$handler] = $this->handler(self::completingSynchronizer(), FakeAutopay::itn(['orderID' => 'O1', 'paymentStatus' => 'SUCCESS']), paymentLocker: $paymentLocker);

        $handler(new NotifyPaymentRequest(HandlerFixtures::HASH));
    }

    public function test_other_order_id_is_cancelled_with_a_message_id_derived_from_the_request_and_the_order_id(): void
    {
        $canceller = self::createMock(TransactionCancellerInterface::class);
        $canceller->expects(self::once())->method('cancel')
            ->with(self::anything(), 'O2', substr(hash('sha256', HandlerFixtures::HASH . '|O2'), 0, 32), self::anything())
            ->willReturn(TransactionCancellerInterface::RESULT_CANCELLED);
        [$handler, , $payment] = $this->handler(self::completingSynchronizer(), FakeAutopay::itn(['orderID' => 'O1', 'paymentStatus' => 'SUCCESS']), canceller: $canceller);
        $details = PaymentDetails::of($payment);
        $details->addOrder('O2', FakeAutopay::SERVICE_ID, 'R2', 1500, 'PLN');
        $details->save();

        $handler(new NotifyPaymentRequest(HandlerFixtures::HASH));
    }

    /**
     * @param list<string> $expectedLevels
     */
    #[TestWith([TransactionCancellerInterface::RESULT_CANCELLED, []])]
    #[TestWith([TransactionCancellerInterface::RESULT_NOTHING_PENDING, []])]
    #[TestWith([TransactionCancellerInterface::RESULT_PAID, ['warning']])]
    public function test_other_order_id_that_could_not_be_cancelled_is_logged_as_a_warning(string $cancelResult, array $expectedLevels): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willReturnCallback(static function (PaymentInterface $payment, string $orderId): string {
            $details = PaymentDetails::of($payment);
            $details->recordOrderStatus($orderId, 'SUCCESS');
            $details->save();
            $payment->setState(PaymentInterface::STATE_COMPLETED);

            return PaymentStateApplierInterface::RESULT_COMPLETED;
        });
        $canceller = self::createStub(TransactionCancellerInterface::class);
        $canceller->method('cancel')->willReturn($cancelResult);
        $logger = new MemoryLogger();
        [$handler, , $payment] = $this->handler($synchronizer, FakeAutopay::itn(['orderID' => 'O1', 'paymentStatus' => 'SUCCESS']), canceller: $canceller, logger: $logger);
        $details = PaymentDetails::of($payment);
        $details->addOrder('O2', FakeAutopay::SERVICE_ID, 'R2', 1500, 'PLN');
        $details->recordOrderStatus('O2', 'FAILURE');
        $details->save();

        $handler(new NotifyPaymentRequest(HandlerFixtures::HASH));

        self::assertSame($expectedLevels, array_column($logger->records, 'level'));
    }

    #[TestWith(['SUCCESS', PaymentRequestInterface::STATE_COMPLETED])]
    #[TestWith(['FAILURE', PaymentRequestInterface::STATE_PROCESSING])]
    #[TestWith(['PENDING', PaymentRequestInterface::STATE_PROCESSING])]
    public function test_capture_request_of_the_notified_order_id_is_completed_once_it_is_paid(string $autopayStatus, string $expectedState): void
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willReturnCallback(static function (PaymentInterface $payment, string $orderId) use ($autopayStatus): string {
            $details = PaymentDetails::of($payment);
            $details->recordOrderStatus($orderId, $autopayStatus);
            $details->save();

            return PaymentStateApplierInterface::RESULT_UNCHANGED;
        });
        $paymentRequestRepository = self::createStub(PaymentRequestRepositoryInterface::class);
        [$handler, $paymentRequest, $payment, $method] = $this->handler($synchronizer, FakeAutopay::itn(['orderID' => 'O1', 'paymentStatus' => $autopayStatus]), $paymentRequestRepository);
        $details = PaymentDetails::of($payment);
        $details->addOrder('O2', FakeAutopay::SERVICE_ID, 'R2', 1500, 'PLN');
        $details->recordOrderStatus('O2', 'SUCCESS');
        $details->save();
        $capture = self::captureRequest($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, 'O1');
        $otherOrderCapture = self::captureRequest($payment, $method, PaymentRequestInterface::ACTION_CAPTURE, 'O2');
        $statusRequest = self::captureRequest($payment, $method, PaymentRequestInterface::ACTION_STATUS, 'O1');
        $requests = [$capture, $otherOrderCapture, $statusRequest, $paymentRequest];
        $paymentRequestRepository->method('findByPaymentIdAndStates')->willReturnCallback(
            static fn (mixed $paymentId, array $states): array => array_values(array_filter($requests, static fn (PaymentRequestInterface $request): bool => in_array($request->getState(), $states, true))),
        );

        $handler(new NotifyPaymentRequest(HandlerFixtures::HASH));

        self::assertSame($expectedState, $capture->getState());
        self::assertSame(['redirect_url' => 'https://' . FakeAutopay::HOST . '/payment/continue/R-O1', 'order_id' => 'O1'], $capture->getResponseData());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $otherOrderCapture->getState());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $statusRequest->getState());
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $paymentRequest->getState());
    }

    private static function completingSynchronizer(): PaymentSynchronizerInterface
    {
        $synchronizer = self::createStub(PaymentSynchronizerInterface::class);
        $synchronizer->method('synchronize')->willReturnCallback(static function (PaymentInterface $payment, string $orderId): string {
            $details = PaymentDetails::of($payment);
            $details->recordOrderStatus($orderId, 'SUCCESS');
            $details->save();
            $payment->setState(PaymentInterface::STATE_COMPLETED);

            return PaymentStateApplierInterface::RESULT_COMPLETED;
        });

        return $synchronizer;
    }

    private static function captureRequest(Payment $payment, PaymentMethod $method, string $action, string $orderId): PaymentRequestInterface
    {
        $request = new PaymentRequest($payment, $method);
        $request->setAction($action);
        $request->setState(PaymentRequestInterface::STATE_PROCESSING);
        $request->setResponseData(['redirect_url' => 'https://' . FakeAutopay::HOST . '/payment/continue/R-' . $orderId, 'order_id' => $orderId]);

        return $request;
    }

    /**
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface>|null $paymentRequestRepository
     *
     * @return array{NotifyPaymentRequestHandler, PaymentRequestInterface, Payment, PaymentMethod}
     */
    private function handler(
        PaymentSynchronizerInterface $synchronizer,
        string $body,
        ?PaymentRequestRepositoryInterface $paymentRequestRepository = null,
        ?TransactionCancellerInterface $canceller = null,
        LoggerInterface $logger = new NullLogger(),
        ?string $startedWith = null,
        ?PaymentLockerInterface $paymentLocker = null,
    ): array {
        $method = HandlerFixtures::method();
        $payment = HandlerFixtures::payment($method, PaymentInterface::STATE_NEW);
        $details = PaymentDetails::of($payment);
        $details->addOrder('O1', FakeAutopay::SERVICE_ID, 'R1', 1500, 'PLN', $startedWith);
        $details->save();
        $paymentRequest = HandlerFixtures::paymentRequest($payment, $method, PaymentRequestInterface::ACTION_NOTIFY);
        $paymentRequest->setPayload(['http_request' => ['content' => $body, 'headers' => []]]);

        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $handler = new NotifyPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            new NotificationParser(new SignatureVerifier()),
            $synchronizer,
            $canceller ?? self::createStub(TransactionCancellerInterface::class),
            $paymentLocker ?? self::createStub(PaymentLockerInterface::class),
            new PaymentStateMachine(),
            $logger,
            $paymentRequestRepository,
        );

        return [$handler, $paymentRequest, $payment, $method];
    }
}
