<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\CommandHandler;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayApiException;
use Refberg\SyliusAutopayPlugin\Command\CancelPaymentRequest;
use Refberg\SyliusAutopayPlugin\CommandHandler\CancelPaymentRequestHandler;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolver;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusAutopayPlugin\Payment\TransactionCancellerInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Tests\Refberg\SyliusAutopayPlugin\Support\FakeAutopay;
use Tests\Refberg\SyliusAutopayPlugin\Support\HandlerFixtures;
use Tests\Refberg\SyliusAutopayPlugin\Support\PaymentStateMachine;

final class CancelPaymentRequestHandlerTest extends TestCase
{
    public function test_failed_cancellation_does_not_stop_the_next_one(): void
    {
        $cancelled = [];
        $canceller = self::createStub(TransactionCancellerInterface::class);
        $canceller->method('cancel')->willReturnCallback(static function (PaymentInterface $payment, string $orderId) use (&$cancelled): string {
            $cancelled[] = $orderId;
            if ('O2' === $orderId) {
                throw new AutopayApiException('Autopay is down.', 503);
            }

            return TransactionCancellerInterface::RESULT_CANCELLED;
        });
        $method = HandlerFixtures::method();
        $payment = HandlerFixtures::payment($method, PaymentInterface::STATE_CANCELLED);
        $details = PaymentDetails::of($payment);
        $details->addOrder('O1', FakeAutopay::SERVICE_ID, 'R1', 1500, 'PLN');
        $details->addOrder('O2', FakeAutopay::SERVICE_ID, 'R2', 1500, 'PLN');
        $details->save();
        $paymentRequest = HandlerFixtures::paymentRequest($payment, $method, PaymentRequestInterface::ACTION_CANCEL);
        $paymentRequestProvider = self::createStub(PaymentRequestProviderInterface::class);
        $paymentRequestProvider->method('provide')->willReturn($paymentRequest);

        $paymentLocker = self::createMock(PaymentLockerInterface::class);
        $paymentLocker->expects(self::once())->method('lock');

        $handler = new CancelPaymentRequestHandler(
            $paymentRequestProvider,
            new CredentialsResolver(),
            $canceller,
            $paymentLocker,
            new PaymentStateMachine(),
        );
        $handler(new CancelPaymentRequest(HandlerFixtures::HASH));

        self::assertSame(['O2', 'O1'], $cancelled);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $paymentRequest->getState());
        self::assertSame(['error' => 'Autopay is down.'], $paymentRequest->getResponseData());
    }
}
