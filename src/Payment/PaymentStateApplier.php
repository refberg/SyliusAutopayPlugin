<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Payment;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use function count;

/**
 * The only place where the plugin changes the state of a Sylius payment.
 *
 * Input must be a transaction read from the Autopay API and validated against the payment.
 * Rules are monotonic, so duplicated or out-of-order notifications cannot change the outcome:
 *  - SUCCESS completes the payment once; a second OrderID of the same payment that succeeds,
 *    or a second successful attempt of the same OrderID, is recorded as a warning and never pays the order twice,
 *  - FAILURE and PENDING never change the payment state: after a FAILURE the customer can still pay
 *    the same OrderID with a new attempt (Autopay documentation), and a failed Sylius payment could not be
 *    completed any more. The customer retries from the order page; unpaid orders are cancelled by Sylius.
 */
final readonly class PaymentStateApplier implements PaymentStateApplierInterface
{
    public function __construct(
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function apply(PaymentInterface $payment, AutopayTransaction $transaction): string
    {
        $details = PaymentDetails::of($payment);
        $details->recordOrderStatus($transaction->orderId, $transaction->status, $transaction->remoteId);

        if (count($transaction->paidRemoteIds) > 1) {
            $details->addWarning(PaymentDetails::WARNING_DUPLICATE_PAYMENT, $transaction->orderId);
            $this->logger->error('Autopay transaction was paid more than once. Manual refund may be needed.', [
                'payment_id' => $payment->getId(),
                'order_id' => $transaction->orderId,
                'remote_ids' => $transaction->paidRemoteIds,
            ]);
        }

        $result = match ($transaction->status) {
            AutopayTransaction::STATUS_SUCCESS => $this->onSuccess($payment, $details, $transaction),
            default => self::RESULT_UNCHANGED,
        };

        $details->save();

        return $result;
    }

    private function onSuccess(PaymentInterface $payment, PaymentDetails $details, AutopayTransaction $transaction): string
    {
        $completedOrderId = $details->getCompletedOrderId();
        if ($completedOrderId === $transaction->orderId) {
            return self::RESULT_UNCHANGED;
        }

        if (null === $completedOrderId && $this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
            $details->setCompletedOrderId($transaction->orderId);

            $this->logger->info('Autopay payment completed.', ['payment_id' => $payment->getId(), 'order_id' => $transaction->orderId, 'remote_id' => $transaction->remoteId]);

            return self::RESULT_COMPLETED;
        }

        $warning = null !== $completedOrderId ? PaymentDetails::WARNING_DUPLICATE_PAYMENT : PaymentDetails::WARNING_LATE_PAYMENT;
        $details->addWarning($warning, $transaction->orderId);

        $this->logger->error('Autopay transaction was paid, but the Sylius payment cannot be completed. Manual refund may be needed.', [
            'payment_id' => $payment->getId(),
            'payment_state' => $payment->getState(),
            'order_id' => $transaction->orderId,
            'reason' => $warning,
        ]);

        return self::RESULT_WARNING;
    }
}
