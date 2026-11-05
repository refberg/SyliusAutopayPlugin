<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\CommandHandler;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayException;
use Refberg\SyliusAutopayPlugin\Command\StatusPaymentRequest;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusAutopayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusAutopayPlugin\Payment\TransactionCancellerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use function array_filter;
use function array_slice;

/**
 * Runs when the customer comes back from Autopay (after-pay) or when an API client asks for the status.
 *
 * The state is read from the Autopay transaction status API; query parameters of the return URL are ignored.
 *
 * @internal
 */
final readonly class StatusPaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    private const MAX_CHECKED_ORDERS = 3;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private PaymentSynchronizerInterface $synchronizer,
        private TransactionCancellerInterface $canceller,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(StatusPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);
        $payment = self::corePayment($paymentRequest);

        try {
            $method = $paymentRequest->getMethod();
            $credentials = $this->credentialsResolver->resolve($method);
            $this->paymentLocker->lock($payment);

            // Transactions started by another Autopay service (before the customer switched methods)
            // cannot be read with these credentials; their ITN still completes the payment.
            $details = PaymentDetails::of($payment);
            $orderIds = array_filter($details->getOpenOrderIds(), static fn (string $orderId): bool => $details->hasOrderOfService($orderId, $credentials->serviceId));
            foreach (array_slice($orderIds, 0, self::MAX_CHECKED_ORDERS) as $orderId) {
                if (PaymentStateApplierInterface::RESULT_COMPLETED === $this->synchronizer->synchronize($payment, $orderId, $credentials)) {
                    $this->onPaymentCompleted($this->canceller, $paymentRequest, $payment, $credentials, $orderId);
                }
            }
        } catch (AutopayException|InvalidConfigurationException $exception) {
            $this->logger->warning('Could not check Autopay transaction status.', [
                'payment_id' => $payment->getId(),
                'reason' => $exception->getMessage(),
            ]);
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $details = PaymentDetails::of($payment);
        $currentOrderId = $details->getCurrentOrderId();

        $this->completeRequest($paymentRequest, [
            'payment_state' => $payment->getState(),
            'order_id' => $currentOrderId,
            'autopay_status' => null !== $currentOrderId ? $details->getOrderStatus($currentOrderId) : null,
        ]);
    }
}
