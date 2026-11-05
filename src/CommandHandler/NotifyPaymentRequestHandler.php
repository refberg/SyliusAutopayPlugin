<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayApiException;
use Refberg\SyliusAutopayPlugin\Api\Model\RefundCreated;
use Refberg\SyliusAutopayPlugin\Command\NotifyPaymentRequest;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusAutopayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusAutopayPlugin\Notification\Notification;
use Refberg\SyliusAutopayPlugin\Notification\NotificationParserInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentSynchronizerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Handles an Autopay ITN (or ISTN about a refund) stored by Sylius' notify endpoint.
 *
 * The hash is verified again here (the notify payment provider already rejected bad ones),
 * then the transaction state is read from the Autopay API, so a stale or replayed ITN
 * cannot move the payment backwards.
 *
 * Transport errors and transient or authorization API errors are rethrown: with the sync:// transport
 * the endpoint answers 5xx and Autopay repeats the ITN.
 *
 * @internal
 */
final readonly class NotifyPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private NotificationParserInterface $notificationParser,
        private PaymentSynchronizerInterface $synchronizer,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(NotifyPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        $payment = self::corePayment($paymentRequest);

        try {
            $method = $paymentRequest->getMethod();
            $credentials = $this->credentialsResolver->resolve($method);
        } catch (InvalidConfigurationException $exception) {
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $notification = $this->notificationParser->parseStoredPayload($paymentRequest->getPayload(), $credentials);
        if (!$notification instanceof Notification) {
            $this->logger->warning('Rejected Autopay ITN with an invalid hash or body.', ['payment_id' => $payment->getId()]);
            $this->failRequest($paymentRequest, 'Invalid notification hash or body.');

            return;
        }

        $this->paymentLocker->lock($payment);

        $orderId = (string) $notification->orderId;
        if (!PaymentDetails::of($payment)->hasOrderOfService($orderId, $credentials->serviceId)) {
            $this->failRequest($paymentRequest, 'Autopay OrderID is not linked to this payment and service ID.');

            return;
        }

        if (Notification::TYPE_REFUND === $notification->type) {
            $this->completeRequest($paymentRequest, ['type' => $notification->type, 'order_id' => $orderId] + $this->handleRefund($notification, $payment));

            return;
        }

        try {
            $result = $this->synchronizer->synchronize($payment, $orderId, $credentials);
        } catch (AutopayApiException $exception) {
            // Rejected credentials are fixed by the merchant, so Autopay should keep repeating the ITN until then.
            if ($exception->isTransient() || $exception->isAuthorizationError()) {
                throw $exception;
            }

            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        if (PaymentStateApplierInterface::RESULT_COMPLETED === $result) {
            $this->restorePaidMethod($payment, $method, $orderId);
        }

        $this->completeRequest($paymentRequest, [
            'type' => $notification->type,
            'order_id' => $orderId,
            'result' => $result,
            'payment_state' => $payment->getState(),
        ]);
    }

    /**
     * ISTN about a refund. A refund requested by Sylius is found by its MessageID (orderOutID); any other refund
     * of the paid OrderID was made in the Autopay panel and is recorded as external. Once done refunds cover
     * the payment, it is moved to "refunded"; a partial refund changes nothing, as Sylius has no state for it.
     *
     * @return array<string, mixed>
     */
    private function handleRefund(Notification $notification, PaymentInterface $payment): array
    {
        $status = match ($notification->status) {
            'SUCCESS' => RefundCreated::STATUS_DONE,
            'FAILURE' => RefundCreated::STATUS_CANCELED,
            default => RefundCreated::STATUS_PENDING,
        };

        $details = PaymentDetails::of($payment);
        $key = $notification->refundId;
        if (null === $key || !$details->hasRefund($key)) {
            if (
                $notification->orderId !== $details->getCompletedOrderId() ||
                null === $notification->amount ||
                $notification->currency !== $payment->getCurrencyCode()
            ) {
                $this->logger->warning('Ignored Autopay refund notification that does not match the paid transaction.', [
                    'payment_id' => $payment->getId(),
                    'order_id' => $notification->orderId,
                    'remote_out_id' => $notification->remoteOutId,
                ]);

                return ['ignored' => true];
            }

            $key = $details->recordExternalRefund((string) $notification->remoteOutId, $status, $notification->amount, (string) $notification->currency);
            $this->logger->info('Recorded an Autopay refund made outside Sylius.', ['payment_id' => $payment->getId(), 'remote_out_id' => $notification->remoteOutId]);
        }

        if ($details->updateRefundStatus($key, $status) && RefundCreated::STATUS_CANCELED === $status) {
            $this->logger->error('Autopay could not make a refund; it has to be handled manually.', ['payment_id' => $payment->getId(), 'refund' => $key]);
        }
        $details->setRefundRemoteOutId($key, (string) $notification->remoteOutId);
        $details->save();

        if (
            $details->finalizedRefundAmount() >= (int) $payment->getAmount() &&
            $this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND)
        ) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        }

        return ['refund' => $key, 'refund_status' => $details->refunds()[$key]['status'] ?? null];
    }
}
