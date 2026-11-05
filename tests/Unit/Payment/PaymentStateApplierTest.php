<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Payment;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentStateApplier;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Payment\PaymentTransitions;
use Tests\Refberg\SyliusAutopayPlugin\Support\MemoryLogger;
use Tests\Refberg\SyliusAutopayPlugin\Support\PaymentStateMachine;

final class PaymentStateApplierTest extends TestCase
{
    private PaymentStateMachine $stateMachine;

    private MemoryLogger $logger;

    private PaymentStateApplier $applier;

    protected function setUp(): void
    {
        $this->stateMachine = new PaymentStateMachine();
        $this->logger = new MemoryLogger();
        $this->applier = new PaymentStateApplier($this->stateMachine, $this->logger);
    }

    public function test_success_completes_a_new_payment(): void
    {
        $payment = self::payment(['O1']);

        self::assertSame(PaymentStateApplier::RESULT_COMPLETED, $this->applier->apply($payment, self::transaction('O1', 'SUCCESS', 'R9')));
        self::assertSame('completed', $payment->getState());
        $details = PaymentDetails::of($payment);
        self::assertSame('O1', $details->getCompletedOrderId());
        self::assertSame('R9', $details->getRemoteId('O1'));
        self::assertSame('SUCCESS', $details->getOrderStatus('O1'));
    }

    public function test_duplicated_success_changes_nothing(): void
    {
        $payment = self::payment(['O1']);
        $this->applier->apply($payment, self::transaction('O1', 'SUCCESS'));

        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::transaction('O1', 'SUCCESS')));
        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $this->stateMachine->appliedTransitions(PaymentTransitions::GRAPH));
        self::assertSame([], PaymentDetails::of($payment)->warnings());
    }

    #[TestWith(['PENDING'])]
    #[TestWith(['FAILURE'])]
    public function test_out_of_order_statuses_never_undo_a_completed_payment(string $status): void
    {
        $payment = self::payment(['O1']);
        $this->applier->apply($payment, self::transaction('O1', 'SUCCESS', 'R1'));

        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::transaction('O1', $status, 'R2')));
        self::assertSame('completed', $payment->getState());
        self::assertSame('SUCCESS', PaymentDetails::of($payment)->getOrderStatus('O1'));
        self::assertSame('R1', PaymentDetails::of($payment)->getRemoteId('O1'));
    }

    public function test_pending_keeps_the_payment_new(): void
    {
        $payment = self::payment(['O1']);

        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::transaction('O1', 'PENDING')));
        self::assertSame('new', $payment->getState());
        self::assertSame([], $this->stateMachine->applied);
    }

    public function test_failure_fails_the_payment_when_nothing_else_is_open(): void
    {
        $payment = self::payment(['O1']);

        self::assertSame(PaymentStateApplier::RESULT_FAILED, $this->applier->apply($payment, self::transaction('O1', 'FAILURE')));
        self::assertSame('failed', $payment->getState());
    }

    public function test_failure_keeps_the_payment_when_another_transaction_may_still_be_paid(): void
    {
        $payment = self::payment(['O1', 'O2']);

        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::transaction('O1', 'FAILURE')));
        self::assertSame('new', $payment->getState());
        self::assertSame(['O2'], PaymentDetails::of($payment)->getOpenOrderIds());
    }

    public function test_failure_of_a_payment_that_cannot_fail_changes_nothing(): void
    {
        $payment = self::payment(['O1']);
        $payment->setState('cancelled');

        self::assertSame(PaymentStateApplier::RESULT_UNCHANGED, $this->applier->apply($payment, self::transaction('O1', 'FAILURE')));
        self::assertSame('cancelled', $payment->getState());
    }

    public function test_second_paid_transaction_is_a_warning_and_never_pays_twice(): void
    {
        $payment = self::payment(['O1', 'O2']);
        $this->applier->apply($payment, self::transaction('O1', 'SUCCESS'));

        self::assertSame(PaymentStateApplier::RESULT_WARNING, $this->applier->apply($payment, self::transaction('O2', 'SUCCESS')));
        self::assertSame(PaymentStateApplier::RESULT_WARNING, $this->applier->apply($payment, self::transaction('O2', 'SUCCESS')));

        self::assertSame('completed', $payment->getState());
        $details = PaymentDetails::of($payment);
        self::assertSame('O1', $details->getCompletedOrderId());
        self::assertSame([['type' => PaymentDetails::WARNING_DUPLICATE_PAYMENT, 'order_id' => 'O2']], $details->warnings());
        self::assertSame([PaymentTransitions::TRANSITION_COMPLETE], $this->stateMachine->appliedTransitions(PaymentTransitions::GRAPH));
        self::assertSame('error', $this->logger->records[1]['level'] ?? null);
    }

    #[TestWith(['failed'])]
    #[TestWith(['cancelled'])]
    public function test_payment_after_failure_or_cancellation_is_a_warning(string $state): void
    {
        $payment = self::payment(['O1']);
        $payment->setState($state);

        self::assertSame(PaymentStateApplier::RESULT_WARNING, $this->applier->apply($payment, self::transaction('O1', 'SUCCESS')));
        self::assertSame($state, $payment->getState());
        self::assertNull(PaymentDetails::of($payment)->getCompletedOrderId());
        self::assertSame([['type' => PaymentDetails::WARNING_LATE_PAYMENT, 'order_id' => 'O1']], PaymentDetails::of($payment)->warnings());
    }

    /**
     * @param list<string> $orderIds
     */
    private static function payment(array $orderIds): Payment
    {
        $payment = new Payment();
        $payment->setAmount(1500);
        $payment->setCurrencyCode('PLN');
        $payment->setState('new');
        $details = PaymentDetails::of($payment);
        foreach ($orderIds as $orderId) {
            $details->addOrder($orderId, '100001', 'R-' . $orderId, 1500, 'PLN');
        }
        $details->save();

        return $payment;
    }

    private static function transaction(string $orderId, string $status, ?string $remoteId = null): AutopayTransaction
    {
        return new AutopayTransaction($orderId, $remoteId, $status, 1500, 'PLN', '100001');
    }
}
