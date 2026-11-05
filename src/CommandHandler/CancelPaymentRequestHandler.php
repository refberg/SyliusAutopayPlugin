<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayException;
use Refberg\SyliusAutopayPlugin\Command\CancelPaymentRequest;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusAutopayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusAutopayPlugin\Payment\TransactionCancellerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use function implode;
use function sprintf;

/**
 * Cancels, with transactionCancel, every Autopay OrderID of the payment that is not paid, after the payment
 * was cancelled in Sylius (e.g. by an administrator). Without it the customer could still pay a cancelled order.
 *
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
        private TransactionCancellerInterface $canceller,
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

        $cancelled = [];
        $errors = [];
        foreach (PaymentDetails::of($payment)->getUnpaidOrderIds() as $orderId) {
            if (!PaymentDetails::of($payment)->hasOrderOfService($orderId, $credentials->serviceId)) {
                $details = PaymentDetails::of($payment);
                $details->addWarning(PaymentDetails::WARNING_NOT_CANCELLED, $orderId);
                $details->save();
                $this->logger->warning('Autopay transaction of another service cannot be cancelled; check it in the Autopay panel.', [
                    'payment_id' => $payment->getId(),
                    'order_id' => $orderId,
                ]);

                continue;
            }

            try {
                $result = $this->canceller->cancel($payment, $orderId, self::messageId($paymentRequest, $orderId), $credentials);
            } catch (AutopayException $exception) {
                $result = $exception->getMessage();
            }

            if (TransactionCancellerInterface::RESULT_CANCELLED === $result) {
                $cancelled[] = $orderId;
            } elseif (TransactionCancellerInterface::RESULT_NOTHING_PENDING !== $result) {
                $reason = TransactionCancellerInterface::RESULT_PAID === $result ? sprintf('Autopay transaction %s was already paid.', $orderId) : $result;
                $errors[] = $reason;
                $this->logger->error('Autopay transaction of a cancelled payment was not cancelled; check it in the Autopay panel.', [
                    'payment_id' => $payment->getId(),
                    'order_id' => $orderId,
                    'reason' => $reason,
                ]);
            }
        }

        if ([] !== $errors) {
            $this->failRequest($paymentRequest, implode(' ', $errors));

            return;
        }

        $this->completeRequest($paymentRequest, ['cancelled_order_ids' => $cancelled]);
    }
}
