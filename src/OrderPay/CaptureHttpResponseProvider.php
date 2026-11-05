<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\OrderPay;

use LogicException;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use function is_string;
use function str_starts_with;

/**
 * Sends the shop customer to the Autopay payment page.
 *
 * With the shop installed, a capture that failed (Autopay refused or could not be reached) shows an error
 * message and goes on to the after-pay page, where the customer can try again; without it, the error
 * is in the payment request for API clients.
 *
 * Sylius 2.2 passes a RequestConfiguration, Sylius 2.3 a Request.
 *
 * @internal
 */
final readonly class CaptureHttpResponseProvider implements HttpResponseProviderInterface
{
    public const FLASH_CAPTURE_FAILED = 'refberg_autopay.ui.payment_start_failed';

    public function __construct(private ?UrlProviderInterface $afterPayUrlProvider = null)
    {
    }

    public function supports(Request|RequestConfiguration $request, PaymentRequestInterface $paymentRequest): bool
    {
        if (PaymentRequestInterface::STATE_FAILED === $paymentRequest->getState()) {
            return $this->afterPayUrlProvider instanceof UrlProviderInterface;
        }

        return PaymentRequestInterface::STATE_PROCESSING === $paymentRequest->getState() &&
            null !== self::redirectUrl($paymentRequest);
    }

    public function getResponse(Request|RequestConfiguration $request, PaymentRequestInterface $paymentRequest): RedirectResponse
    {
        if (PaymentRequestInterface::STATE_FAILED === $paymentRequest->getState() && $this->afterPayUrlProvider instanceof UrlProviderInterface) {
            $httpRequest = $request instanceof RequestConfiguration ? $request->getRequest() : $request;
            $session = $httpRequest->hasSession() ? $httpRequest->getSession() : null;
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', self::FLASH_CAPTURE_FAILED);
            }

            return new RedirectResponse($this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_PATH));
        }

        $url = self::redirectUrl($paymentRequest) ?? throw new LogicException('Autopay redirect URL is missing.');

        return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
    }

    private static function redirectUrl(PaymentRequestInterface $paymentRequest): ?string
    {
        $url = $paymentRequest->getResponseData()['redirect_url'] ?? null;

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }
}
