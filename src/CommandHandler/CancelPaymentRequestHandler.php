<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Command\CancelPaymentRequest;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusAutopayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use function sprintf;

/**
 * Runs after the payment was cancelled in Sylius (e.g. by an administrator).
 *
 * The Autopay online payments API has no call that cancels a started transaction (assumption A6
 * in docs/autopay.md), so open transactions are only flagged and logged. If the customer still pays one,
 * the payment stays cancelled and gets the "payment_completed_after_failure" warning.
 * The "refberg_autopay.transaction_validity" parameter limits how long a transaction can be paid.
 *
 * @internal
 */
final readonly class CancelPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
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
            $this->credentialsResolver->resolve($paymentRequest->getMethod());
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
        $openOrderIds = $details->getOpenOrderIds();
        foreach ($openOrderIds as $orderId) {
            $details->addWarning(PaymentDetails::WARNING_NOT_CANCELLED, $orderId);
            $this->logger->warning('Autopay transaction of a cancelled payment cannot be cancelled at Autopay and may still be paid.', [
                'payment_id' => $payment->getId(),
                'order_id' => $orderId,
            ]);
        }
        $details->save();

        $this->completeRequest($paymentRequest, ['open_order_ids' => $openOrderIds]);
    }
}
