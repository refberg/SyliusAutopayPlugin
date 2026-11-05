<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\OrderPay;

use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\FinalUrlProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * After returning from Autopay: when the transaction is still in progress, show the thank-you page
 * instead of offering to pay again. The payment itself stays unpaid until Autopay confirms it.
 *
 * Completed and failed payments are left to the default Sylius flow.
 *
 * @internal
 */
final readonly class StatusHttpResponseProvider implements HttpResponseProviderInterface
{
    public function __construct(private FinalUrlProviderInterface $finalUrlProvider)
    {
    }

    public function supports(Request|RequestConfiguration $request, PaymentRequestInterface $paymentRequest): bool
    {
        return PaymentRequestInterface::STATE_COMPLETED === $paymentRequest->getState() &&
            AutopayTransaction::STATUS_PENDING === ($paymentRequest->getResponseData()['autopay_status'] ?? null);
    }

    public function getResponse(Request|RequestConfiguration $request, PaymentRequestInterface $paymentRequest): RedirectResponse
    {
        return new RedirectResponse($this->finalUrlProvider->getUrl(null));
    }
}
