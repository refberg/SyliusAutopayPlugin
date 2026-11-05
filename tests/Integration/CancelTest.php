<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Integration;

use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusAutopayPlugin\Support\FakeAutopay;
use function array_map;
use function json_encode;

final class CancelTest extends IntegrationTestCase
{
    public function test_cancelling_the_payment_in_sylius_cancels_the_open_autopay_transaction(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        self::assertSame([$orderId], $this->autopay->cancelRequests());
        self::assertSame('FAILURE', $this->autopay->transaction($orderId)['attempts'][0]['paymentStatus']);
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
        self::assertSame(['cancelled_order_ids' => [$orderId]], $requests[0]->getResponseData());
        self::assertSame('cancelled', $this->reloadPayment($payment)->getState());
        self::assertSame('FAILURE', $this->details($payment)->getOrderStatus($orderId));

        // The FAILURE ITN that follows the cancellation changes nothing.
        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
        self::assertSame('cancelled', $this->reloadPayment($payment)->getState());
    }

    public function test_payment_completed_before_the_cancel_reached_autopay_is_flagged(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        // Paid at Autopay, ITN not delivered yet.
        $this->autopay->setStatus($orderId, 'SUCCESS');

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertStringContainsString('INCORRECT_PAYMENT_STATUS', (string) json_encode($requests[0]->getResponseData()));

        $this->notifyOrder($orderId);
        $payment = $this->reloadPayment($payment);
        self::assertSame('cancelled', $payment->getState());
        $types = array_map(static fn (array $warning): mixed => $warning['type'] ?? null, PaymentDetails::of($payment)->warnings());
        self::assertSame([PaymentDetails::WARNING_LATE_PAYMENT], $types);
    }

    public function test_transaction_of_another_autopay_service_is_only_flagged(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $payment = $this->reloadPayment($payment);
        $payment->setMethod($this->createAutopayMethod('AUTOPAY2', '999999', 'other-shared-key'));
        $this->em()->flush();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        self::assertSame([], $this->autopay->cancelRequests());
        self::assertSame([['type' => PaymentDetails::WARNING_NOT_CANCELLED, 'order_id' => $orderId]], $this->details($payment)->warnings());
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL)[0]->getState());
    }

    public function test_cancel_request_for_a_payment_that_was_not_cancelled_in_sylius_keeps_the_transaction_open(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();

        $this->dispatchPaymentRequest($payment, PaymentRequestInterface::ACTION_CANCEL);

        self::assertSame([], $this->autopay->cancelRequests());
        self::assertSame('PENDING', $this->autopay->transaction($orderId)['attempts'][0]['paymentStatus']);
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertSame(FakeAutopay::SERVICE_ID, $this->autopay->transaction($orderId)['fields']['ServiceID']);
    }

    private function applyTransition(PaymentInterface $payment, string $transition): void
    {
        $payment = $this->reloadPayment($payment);
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');

        $stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
        $this->em()->flush();
    }
}
