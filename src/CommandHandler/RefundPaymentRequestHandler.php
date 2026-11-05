<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Api\AutopayClientInterface;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayException;
use Refberg\SyliusAutopayPlugin\Command\RefundPaymentRequest;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusAutopayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use function sprintf;
use function str_replace;
use function strtolower;

/**
 * Requests a refund of the Autopay transaction that paid the payment: the payment amount
 * less refunds that are not canceled.
 *
 * MessageID is derived from the payment request hash, so a redelivered request does not create
 * a second refund in Autopay. Autopay does not notify about refunds; their final status is read
 * by the refberg:autopay:refresh-refunds command.
 *
 * @internal
 */
final readonly class RefundPaymentRequestHandler
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

    public function __invoke(RefundPaymentRequest $command): void
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
        if (PaymentInterface::STATE_REFUNDED !== $payment->getState()) {
            $this->failRequest($paymentRequest, sprintf('Payment in state "%s" cannot be refunded.', (string) $payment->getState()));

            return;
        }

        $details = PaymentDetails::of($payment);
        $orderId = $details->getCompletedOrderId();
        $remoteId = null !== $orderId ? $details->getRemoteId($orderId) : null;
        if (null === $orderId || null === $remoteId) {
            $this->logger->error('Autopay refund not requested: the payment was not completed through Autopay.', ['payment_id' => $payment->getId()]);
            $this->failRequest($paymentRequest, 'No completed Autopay transaction for this payment.');

            return;
        }

        if ($details->hasActiveRefund()) {
            $this->completeRequest($paymentRequest, ['order_id' => $orderId, 'already_requested' => true]);

            return;
        }

        $amount = (int) $payment->getAmount() - $details->activeRefundAmount();
        $currency = (string) $payment->getCurrencyCode();
        $messageId = str_replace('-', '', strtolower((string) $paymentRequest->getId()));

        try {
            $refund = $this->client->createRefund($credentials, $remoteId, $amount, $currency, $messageId);
        } catch (AutopayException $exception) {
            $this->logger->error('Autopay refund request failed; refund has to be handled manually.', [
                'payment_id' => $payment->getId(),
                'remote_id' => $remoteId,
                'reason' => $exception->getMessage(),
            ]);
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $details->recordRefund($refund->messageId, $remoteId, $refund->status, $amount, $currency);
        $details->save();

        $this->completeRequest($paymentRequest, [
            'order_id' => $orderId,
            'message_id' => $refund->messageId,
            'refund_status' => $refund->status,
        ]);
    }
}
