<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayApiException;
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

/**
 * Handles an Autopay ITN stored by Sylius' notify endpoint.
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

        if (!PaymentDetails::of($payment)->hasOrderOfService($notification->orderId, $credentials->serviceId)) {
            $this->failRequest($paymentRequest, 'Autopay OrderID is not linked to this payment and service ID.');

            return;
        }

        try {
            $result = $this->synchronizer->synchronize($payment, $notification->orderId, $credentials);
        } catch (AutopayApiException $exception) {
            // Rejected credentials are fixed by the merchant, so Autopay should keep repeating the ITN until then.
            if ($exception->isTransient() || $exception->isAuthorizationError()) {
                throw $exception;
            }

            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        if (PaymentStateApplierInterface::RESULT_COMPLETED === $result) {
            $this->restorePaidMethod($payment, $method, $notification->orderId);
        }

        $this->completeRequest($paymentRequest, [
            'type' => $notification->type,
            'order_id' => $notification->orderId,
            'result' => $result,
            'payment_state' => $payment->getState(),
        ]);
    }
}
