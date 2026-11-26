<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\CommandHandler;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\AutopayClient;
use Refberg\SyliusAutopayPlugin\Api\Model\RefundCreated;
use Refberg\SyliusAutopayPlugin\Command\RefundPaymentRequest;
use Refberg\SyliusAutopayPlugin\CommandHandler\RefundPaymentRequestHandler;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolver;
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
use function str_repeat;

final class RefundPaymentRequestHandlerTest extends TestCase
{
    public function test_request_that_is_not_new_is_left_alone(): void
    {
        [$handler, $autopay, $paymentRequest] = $this->handler();
        $paymentRequest->setState(PaymentRequestInterface::STATE_PROCESSING);

        $handler(new RefundPaymentRequest(HandlerFixtures::HASH));

        self::assertSame([], $autopay->requests());
        self::assertSame(PaymentRequestInterface::STATE_PROCESSING, $paymentRequest->getState());
        self::assertSame([], $paymentRequest->getResponseData());
    }

    public function test_payment_not_completed_through_autopay_fails_without_calling_the_api(): void
    {
        [$handler, $autopay, $paymentRequest] = $this->handler();

        $handler(new RefundPaymentRequest(HandlerFixtures::HASH));

        self::assertSame([], $autopay->requests());
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'No completed Autopay transaction for this payment.'], $paymentRequest->getResponseData());
    }

    public function test_active_refund_is_not_requested_again(): void
    {
        $paymentLocker = self::createMock(PaymentLockerInterface::class);
        $paymentLocker->expects(self::once())->method('lock');
        [$handler, $autopay, $paymentRequest, $payment] = $this->handler($paymentLocker);
        $details = PaymentDetails::of($payment);
        $details->addOrder('O1', FakeAutopay::SERVICE_ID, 'R1', 1500, 'PLN');
        $details->setCompletedOrderId('O1');
        $details->recordRefund(str_repeat('a', 32), 'R1', RefundCreated::STATUS_PENDING, 1500, 'PLN');
        $details->save();

        $handler(new RefundPaymentRequest(HandlerFixtures::HASH));

        self::assertSame([], $autopay->requests());
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $paymentRequest->getState());
        self::assertSame(['order_id' => 'O1', 'already_requested' => true], $paymentRequest->getResponseData());
        self::assertCount(1, PaymentDetails::of($payment)->refunds());
    }

    /**
     * @return array{RefundPaymentRequestHandler, FakeAutopay, PaymentRequestInterface, PaymentInterface}
     */
    private function handler(?PaymentLockerInterface $paymentLocker = null): array
    {
        $autopay = new FakeAutopay();
        $method = HandlerFixtures::method();
        $payment = HandlerFixtures::payment($method, PaymentInterface::STATE_REFUNDED);
        $paymentRequest = HandlerFixtures::paymentRequest($payment, $method, PaymentRequestInterface::ACTION_REFUND);
        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $handler = new RefundPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            new AutopayClient(new MockHttpClient($autopay), new SignatureVerifier()),
            $paymentLocker ?? self::createStub(PaymentLockerInterface::class),
            new PaymentStateMachine(),
        );

        return [$handler, $autopay, $paymentRequest, $payment];
    }
}
