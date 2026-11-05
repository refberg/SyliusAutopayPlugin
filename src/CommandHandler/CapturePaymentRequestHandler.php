<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\CommandHandler;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Api\AutopayClientInterface;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayException;
use Refberg\SyliusAutopayPlugin\Command\CapturePaymentRequest;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusAutopayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusAutopayPlugin\Order\ExtOrderIdGeneratorInterface;
use Refberg\SyliusAutopayPlugin\Order\TransactionRequestFactoryInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use function sprintf;

/**
 * Starts an Autopay transaction in the background and stores the redirect URL in the payment request response data.
 *
 * It never completes the payment: only a verified ITN or a direct API query can.
 *
 * @internal
 */
final readonly class CapturePaymentRequestHandler
{
    use PaymentRequestHandlerTrait;

    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsResolverInterface $credentialsResolver,
        private AutopayClientInterface $client,
        private TransactionRequestFactoryInterface $transactionRequestFactory,
        private ExtOrderIdGeneratorInterface $extOrderIdGenerator,
        private PaymentLockerInterface $paymentLocker,
        private StateMachineInterface $stateMachine,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CapturePaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        // A processing request already has an Autopay transaction: re-sending the customer there is enough.
        if (PaymentRequestInterface::STATE_NEW !== $paymentRequest->getState()) {
            return;
        }

        $payment = self::corePayment($paymentRequest);

        try {
            $credentials = $this->credentialsResolver->resolve($paymentRequest->getMethod());
            $this->paymentLocker->lock($payment);
            if (PaymentInterface::STATE_NEW !== $payment->getState()) {
                $this->failRequest($paymentRequest, sprintf('Payment in state "%s" cannot be paid.', (string) $payment->getState()));

                return;
            }

            $orderId = $this->extOrderIdGenerator->generate($payment, $paymentRequest);
            $parameters = $this->transactionRequestFactory->create($paymentRequest, $payment, $orderId);
            $started = $this->client->startTransaction($credentials, $parameters);
        } catch (AutopayException|InvalidConfigurationException|InvalidArgumentException $exception) {
            $this->logger->warning('Could not start Autopay transaction.', [
                'payment_id' => $payment->getId(),
                'reason' => $exception->getMessage(),
            ]);
            $this->failRequest($paymentRequest, $exception->getMessage());

            return;
        }

        $details = PaymentDetails::of($payment);
        $details->addOrder($orderId, $credentials->serviceId, $started->remoteId, (int) $payment->getAmount(), (string) $payment->getCurrencyCode());
        $details->save();

        $paymentRequest->setResponseData([
            'redirect_url' => $started->redirectUrl,
            'order_id' => $orderId,
            'remote_id' => $started->remoteId,
        ]);
        $this->transitionRequest($paymentRequest, PaymentRequestTransitions::TRANSITION_PROCESS);
    }
}
