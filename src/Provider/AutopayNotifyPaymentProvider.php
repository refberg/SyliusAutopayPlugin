<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Provider;

use Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusAutopayPlugin\Config\InvalidConfigurationException;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Finds the payment an Autopay ITN belongs to.
 *
 * Runs before Sylius stores anything, so ITNs with a missing or wrong hash are rejected with HTTP 400
 * and never create a payment request. The payment is found by the OrderID (and service ID) stored
 * in its details, not by its current payment method.
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

        $paymentId = $this->extOrderIdGenerator->extractPaymentId($notification->orderId);
        $payment = null !== $paymentId ? $this->paymentRepository->find($paymentId) : null;
        if (!$payment instanceof PaymentInterface || !PaymentDetails::of($payment)->hasOrderOfService($notification->orderId, $credentials->serviceId)) {
            throw new NotFoundHttpException('No payment matches this Autopay notification.');
        }

        return $payment;
    }
}
