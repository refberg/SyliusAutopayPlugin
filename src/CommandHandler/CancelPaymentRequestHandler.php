<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Api\AutopayClientInterface;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayException;
use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Refberg\SyliusAutopayPlugin\Api\Model\TransactionCancelled;
use Refberg\SyliusAutopayPlugin\Command\CancelPaymentRequest;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusAutopayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use function hash;
use function implode;
use function sprintf;
use function substr;

/**
 * Cancels, with transactionCancel, every Autopay OrderID of the payment that may still be paid, after the payment
 * was cancelled in Sylius (e.g. by an administrator). Without it the customer could still pay a cancelled order.
 *
 * MessageID is derived from the payment request hash and the OrderID, so a redelivered request is not processed twice.
 * OrderIDs of another Autopay service (started before the customer switched methods) cannot be cancelled with
 * these credentials and are only flagged.
 *
 * @internal
 */
final readonly class CancelPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private AutopayClientInterface $client,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CancelPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        if (PaymentRequestInterface::STATE_NEW !== $paymentRequest->getState()) {
            return;
        }

        $payment = self::corePayment($paymentRequest);

        try {
            $credentials = $this->credentialsResolver->resolve($paymentRequest->getMethod());
        } catch (InvalidConfigurationException $exception) {
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $this->paymentLocker->lock($payment);
        if (PaymentInterface::STATE_CANCELLED !== $payment->getState()) {
            $this->failRequest($paymentRequest, sprintf('Payment in state "%s" cannot be cancelled.', (string) $payment->getState()));

            return;
        }

        $details = PaymentDetails::of($payment);
        $cancelled = [];
        $errors = [];
        foreach ($details->getOpenOrderIds() as $orderId) {
            if (!$details->hasOrderOfService($orderId, $credentials->serviceId)) {
                $details->addWarning(PaymentDetails::WARNING_NOT_CANCELLED, $orderId);
                $this->logger->warning('Autopay transaction of another service cannot be cancelled; check it in the Autopay panel.', [
                    'payment_id' => $payment->getId(),
                    'order_id' => $orderId,
                ]);

                continue;
            }

            $messageId = substr(hash('sha256', $paymentRequest->getId() . '|' . $orderId), 0, 32);

            try {
                $result = $this->client->cancelTransaction($credentials, $orderId, $messageId);
            } catch (AutopayException $exception) {
                $errors[] = $exception->getMessage();
                $this->logger->error('Could not cancel Autopay transaction; check it in the Autopay panel.', [
                    'payment_id' => $payment->getId(),
                    'order_id' => $orderId,
                    'reason' => $exception->getMessage(),
                ]);

                continue;
            }

            if (
                ($result->confirmed && TransactionCancelled::CANCELED_FULLY === $result->reason) ||
                (!$result->confirmed && TransactionCancelled::TRANSACTION_NOT_FOUND === $result->reason)
            ) {
                $details->recordOrderStatus($orderId, AutopayTransaction::STATUS_FAILURE);
                $cancelled[] = $orderId;

                continue;
            }

            // CANCELED_PARTIALLY or INCORRECT_PAYMENT_STATUS: an attempt was probably paid; its ITN records a warning.
            $errors[] = sprintf('Autopay transaction %s was not fully cancelled (%s).', $orderId, $result->reason ?? 'no reason');
            $this->logger->error('Autopay transaction was not fully cancelled and may be paid; check it in the Autopay panel.', [
                'payment_id' => $payment->getId(),
                'order_id' => $orderId,
                'reason' => $result->reason,
            ]);
        }
        $details->save();

        if ([] !== $errors) {
            $this->failRequest($paymentRequest, implode(' ', $errors));

            return;
        }

        $this->completeRequest($paymentRequest, ['cancelled_order_ids' => $cancelled]);
    }
}
