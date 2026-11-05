<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Provider;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusAutopayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusAutopayPlugin\Notification\ConfirmationFactoryInterface;
use Refberg\SyliusAutopayPlugin\Notification\Notification;
use Refberg\SyliusAutopayPlugin\Notification\NotificationParserInterface;
use Refberg\SyliusAutopayPlugin\Order\ExtOrderIdGeneratorInterface;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Sylius\Bundle\PaymentBundle\Provider\NotifyPaymentProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Finds the payment an Autopay ITN or ISTN belongs to.
 *
 * Runs before Sylius stores anything, so notifications with a missing or wrong hash are rejected with HTTP 400
 * and never create a payment request. The payment is found by the OrderID (and service ID) stored
 * in its details, not by its current payment method.
 *
 * Correctly signed notifications that need no processing are confirmed without storing them, so Autopay does not
 * repeat them for days: ISTNs about settlements, and notifications of OrderIDs no Sylius payment knows
 * (started outside Sylius on the same service, or by a capture that timed out), which are logged.
 *
 * @internal
 */
final readonly class AutopayNotifyPaymentProvider implements NotifyPaymentProviderInterface
{
    /**
     * @param RepositoryInterface<PaymentInterface> $paymentRepository
     */
    public function __construct(
        private CredentialsResolverInterface $credentialsResolver,
        private NotificationParserInterface $notificationParser,
        private ExtOrderIdGeneratorInterface $extOrderIdGenerator,
        private RepositoryInterface $paymentRepository,
        private ConfirmationFactoryInterface $confirmationFactory,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(Request $request, PaymentMethodInterface $paymentMethod): bool
    {
        return $this->credentialsResolver->supports($paymentMethod);
    }

    public function getPayment(Request $request, PaymentMethodInterface $paymentMethod): PaymentInterface
    {
        try {
            $credentials = $this->credentialsResolver->resolve($paymentMethod);
        } catch (InvalidConfigurationException) {
            throw new ServiceUnavailableHttpException(null, 'Autopay payment method is not configured.');
        }

        $notification = $this->notificationParser->parse($request->getContent(), $credentials);
        if (!$notification instanceof Notification) {
            throw new BadRequestHttpException('Invalid Autopay notification hash or body.');
        }

        if (Notification::TYPE_SETTLEMENT === $notification->type) {
            throw new AcknowledgedNotificationException($this->confirmationFactory->create($notification, $credentials, true));
        }

        $orderId = (string) $notification->orderId;
        $paymentId = $this->extOrderIdGenerator->extractPaymentId($orderId);
        $payment = null !== $paymentId ? $this->paymentRepository->find($paymentId) : null;
        if (!$payment instanceof PaymentInterface || !PaymentDetails::of($payment)->hasOrderOfService($orderId, $credentials->serviceId)) {
            $this->logger->error('Autopay notification matches no Sylius payment; it was confirmed and ignored.', [
                'order_id' => $notification->orderId,
                'remote_id' => $notification->remoteId,
                'remote_out_id' => $notification->remoteOutId,
                'type' => $notification->type,
                'status' => $notification->status,
            ]);

            throw new AcknowledgedNotificationException($this->confirmationFactory->create($notification, $credentials, true));
        }

        return $payment;
    }
}
