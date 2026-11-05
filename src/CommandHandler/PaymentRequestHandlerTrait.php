<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\CommandHandler;

use LogicException;
use Psr\Log\LoggerInterface;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayException;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\TransactionCancellerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use function hash;
use function substr;

/**
 * @internal
 *
 * @property StateMachineInterface $stateMachine
 * @property LoggerInterface $logger
 */
trait PaymentRequestHandlerTrait
{
    /**
     * @param array<string, mixed> $responseData
     */
    private function completeRequest(PaymentRequestInterface $paymentRequest, array $responseData): void
    {
        $paymentRequest->setResponseData($responseData);
        $this->transitionRequest($paymentRequest, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    private function failRequest(PaymentRequestInterface $paymentRequest, string $reason): void
    {
        $paymentRequest->setResponseData(['error' => $reason]);
        $this->transitionRequest($paymentRequest, PaymentRequestTransitions::TRANSITION_FAIL);
    }

    private function transitionRequest(PaymentRequestInterface $paymentRequest, string $transition): void
    {
        if ($this->stateMachine->can($paymentRequest, PaymentRequestTransitions::GRAPH, $transition)) {
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, $transition);
        }
    }

    /**
     * After an OrderID completed the payment: restores the paid method and cancels the other OrderIDs
     * of the payment that could still be paid (another tab, a double click), so the order is not paid twice.
     */
    private function onPaymentCompleted(
        TransactionCancellerInterface $canceller,
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        AutopayCredentials $credentials,
        string $orderId,
    ): void {
        $this->restorePaidMethod($payment, $paymentRequest->getMethod(), $orderId);

        $details = PaymentDetails::of($payment);
        foreach ($details->getOpenOrderIds() as $openOrderId) {
            if (!$details->hasOrderOfService($openOrderId, $credentials->serviceId)) {
                continue;
            }

            try {
                $result = $canceller->cancel($payment, $openOrderId, self::messageId($paymentRequest, $openOrderId), $credentials);
            } catch (AutopayException $exception) {
                $result = $exception->getMessage();
            }

            if (TransactionCancellerInterface::RESULT_CANCELLED !== $result) {
                $this->logger->warning('Could not cancel another Autopay transaction of a paid payment; check it in the Autopay panel.', [
                    'payment_id' => $payment->getId(),
                    'order_id' => $openOrderId,
                    'reason' => $result,
                ]);
            }
        }
    }

    /**
     * MessageID of a call made for one OrderID while handling a payment request (32 hex characters).
     */
    private static function messageId(PaymentRequestInterface $paymentRequest, string $orderId): string
    {
        return substr(hash('sha256', $paymentRequest->getId() . '|' . $orderId), 0, 32);
    }

    /**
     * Refunds from Sylius use the payment's method, so it must be the Autopay method that was paid:
     * the one that started the OrderID. With several Sylius methods on one service ID, the ITN address
     * can be another method's, so nothing is changed when the payment already has the method that started it.
     */
    private function restorePaidMethod(PaymentInterface $payment, PaymentMethodInterface $method, string $orderId): void
    {
        $startedWith = PaymentDetails::of($payment)->getMethodCode($orderId);
        if ($payment->getMethod() === $method || (null !== $startedWith && $payment->getMethod()?->getCode() === $startedWith)) {
            return;
        }

        if (null !== $startedWith && $method->getCode() !== $startedWith) {
            $this->logger->warning('Autopay paid the payment with a method other than the current one; check the payment method.', [
                'payment_id' => $payment->getId(),
                'order_id' => $orderId,
                'started_with_method' => $startedWith,
            ]);

            return;
        }

        $previousMethod = $payment->getMethod();
        $payment->setMethod($method);

        // The applier saved its own details; a fresh read keeps completed_order_id.
        $details = PaymentDetails::of($payment);
        $details->addWarning(PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, $orderId);
        $details->save();
        $this->logger->warning('Autopay paid the payment after the customer switched to another payment method; the Autopay method was restored.', [
            'payment_id' => $payment->getId(),
            'order_id' => $orderId,
            'switched_to_method' => $previousMethod?->getCode(),
        ]);
    }

    private static function corePayment(PaymentRequestInterface $paymentRequest): PaymentInterface
    {
        $payment = $paymentRequest->getPayment();
        if (!$payment instanceof PaymentInterface) {
            throw new LogicException('Autopay payment requests require a Sylius Core payment.');
        }

        return $payment;
    }
}
