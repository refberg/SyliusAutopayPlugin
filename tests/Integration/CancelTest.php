<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Integration;

use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Response;
use function array_map;

/**
 * Assumption A6 (docs/autopay.md): the Autopay API has no call that cancels a started transaction.
 */
final class CancelTest extends IntegrationTestCase
{
    public function test_cancelling_the_payment_flags_the_open_autopay_transaction(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();

        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
        self::assertSame(['open_order_ids' => [$orderId]], $requests[0]->getResponseData());
        self::assertSame('cancelled', $this->reloadPayment($payment)->getState());
        self::assertSame([['type' => PaymentDetails::WARNING_NOT_CANCELLED, 'order_id' => $orderId]], $this->details($payment)->warnings());
        self::assertCount(1, $this->autopay->requests());
    }

    public function test_payment_made_after_the_cancellation_does_not_complete_the_payment(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $this->applyTransition($payment, PaymentTransitions::TRANSITION_CANCEL);

        $this->autopay->setStatus($orderId, 'SUCCESS');
        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());

        $payment = $this->reloadPayment($payment);
        self::assertSame('cancelled', $payment->getState());
        $types = array_map(static fn (array $warning): mixed => $warning['type'] ?? null, PaymentDetails::of($payment)->warnings());
        self::assertContains(PaymentDetails::WARNING_LATE_PAYMENT, $types);
    }

    public function test_cancel_request_for_a_payment_that_was_not_cancelled_in_sylius_fails(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();

        $this->dispatchPaymentRequest($payment, PaymentRequestInterface::ACTION_CANCEL);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_CANCEL);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertSame([], $this->details($payment)->warnings());
    }

    private function applyTransition(PaymentInterface $payment, string $transition): void
    {
        $payment = $this->reloadPayment($payment);
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');

        $stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
        $this->em()->flush();
    }
}
