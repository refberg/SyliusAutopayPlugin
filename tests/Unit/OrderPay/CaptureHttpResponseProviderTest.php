<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\OrderPay;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\OrderPay\CaptureHttpResponseProvider;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CaptureHttpResponseProviderTest extends TestCase
{
    private const AUTOPAY_URL = 'https://testpay.autopay.eu/payment/continue/R1/ABCDEFGH';

    public function test_processing_request_redirects_to_the_autopay_page(): void
    {
        $provider = new CaptureHttpResponseProvider();
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::STATE_PROCESSING, self::AUTOPAY_URL);

        self::assertTrue($provider->supports(new Request(), $paymentRequest));
        $response = $provider->getResponse(new Request(), $paymentRequest);
        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame(self::AUTOPAY_URL, $response->getTargetUrl());
    }

    #[TestWith(['http://testpay.autopay.eu/payment/continue/R1/ABCDEFGH'])]
    #[TestWith(['javascript:alert(1)'])]
    #[TestWith(['//evil.example/pay'])]
    #[TestWith([null])]
    public function test_redirect_url_other_than_https_is_not_supported(?string $url): void
    {
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::STATE_PROCESSING, $url);

        self::assertFalse((new CaptureHttpResponseProvider())->supports(new Request(), $paymentRequest));
    }

    #[TestWith([PaymentRequestInterface::STATE_NEW])]
    #[TestWith([PaymentRequestInterface::STATE_COMPLETED])]
    #[TestWith([PaymentRequestInterface::STATE_FAILED])]
    public function test_request_that_is_not_processing_is_not_supported(string $state): void
    {
        $paymentRequest = $this->paymentRequest($state, self::AUTOPAY_URL);

        self::assertFalse((new CaptureHttpResponseProvider())->supports(new Request(), $paymentRequest));
    }

    private function paymentRequest(string $state, ?string $redirectUrl): PaymentRequestInterface
    {
        $paymentRequest = self::createStub(PaymentRequestInterface::class);
        $paymentRequest->method('getState')->willReturn($state);
        $paymentRequest->method('getResponseData')->willReturn(null === $redirectUrl ? [] : ['redirect_url' => $redirectUrl]);

        return $paymentRequest;
    }
}
