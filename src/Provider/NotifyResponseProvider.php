<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Provider;

use Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusAutopayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusAutopayPlugin\Notification\ConfirmationFactoryInterface;
use Refberg\SyliusAutopayPlugin\Notification\Notification;
use Refberg\SyliusAutopayPlugin\Notification\NotificationParserInterface;
use Sylius\Bundle\PaymentBundle\Provider\NotifyResponseProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a stored Autopay ITN with the signed confirmation document.
 *
 * The ITN is CONFIRMED once it was processed, also when the plugin rejected it for good
 * (e.g. the transaction does not match the payment), so Autopay stops repeating it.
 * Errors worth a retry never get here: they end the request with HTTP 5xx.
 * Other gateways keep the core response.
 *
 * @internal
 */
final readonly class NotifyResponseProvider implements NotifyResponseProviderInterface
{
    public function __construct(
        private NotifyResponseProviderInterface $decorated,
        private CredentialsResolverInterface $credentialsResolver,
        private NotificationParserInterface $notificationParser,
        private ConfirmationFactoryInterface $confirmationFactory,
    ) {
    }

    public function provide(PaymentRequestInterface $paymentRequest): Response
    {
        $method = $paymentRequest->getMethod();
        if (!$this->credentialsResolver->supports($method)) {
            return $this->decorated->provide($paymentRequest);
        }

        try {
            $credentials = $this->credentialsResolver->resolve($method);
        } catch (InvalidConfigurationException) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $notification = $this->notificationParser->parseStoredPayload($paymentRequest->getPayload(), $credentials);
        if (!$notification instanceof Notification) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        return new Response(
            $this->confirmationFactory->create($notification, $credentials, true),
            Response::HTTP_OK,
            ['Content-Type' => 'application/xml; charset=UTF-8'],
        );
    }
}
