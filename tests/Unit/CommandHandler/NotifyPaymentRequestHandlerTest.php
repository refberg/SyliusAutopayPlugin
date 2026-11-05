<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\CommandHandler;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayApiException;
use Refberg\SyliusAutopayPlugin\Command\NotifyPaymentRequest;
use Refberg\SyliusAutopayPlugin\CommandHandler\NotifyPaymentRequestHandler;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolver;
use Refberg\SyliusAutopayPlugin\Notification\NotificationParser;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifier;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Tests\Refberg\SyliusAutopayPlugin\Support\FakeAutopay;
use Tests\Refberg\SyliusAutopayPlugin\Support\HandlerFixtures;
use Tests\Refberg\SyliusAutopayPlugin\Support\PaymentStateMachine;

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
        self::assertSame($method, $payment->getMethod());
        self::assertSame([['type' => PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, 'order_id' => 'O1']], PaymentDetails::of($payment)->warnings());
    }

    /**
     * @return array{NotifyPaymentRequestHandler, PaymentRequestInterface, Payment, PaymentMethod}
     */
    private function handler(PaymentSynchronizerInterface $synchronizer, string $body): array
    {
        $method = HandlerFixtures::method();
        $payment = HandlerFixtures::payment($method, PaymentInterface::STATE_NEW);
        $details = PaymentDetails::of($payment);
        $details->addOrder('O1', FakeAutopay::SERVICE_ID, 'R1', 1500, 'PLN');
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
            self::createStub(PaymentLockerInterface::class),
            new PaymentStateMachine(),
        );

        return [$handler, $paymentRequest, $payment, $method];
    }
}
