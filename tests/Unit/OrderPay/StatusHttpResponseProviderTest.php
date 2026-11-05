<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\OrderPay;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Refberg\SyliusAutopayPlugin\OrderPay\StatusHttpResponseProvider;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\FinalUrlProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Request;

final class StatusHttpResponseProviderTest extends TestCase
{
    public function test_transaction_still_processed_by_autopay_shows_the_thank_you_page(): void
    {
        $provider = $this->provider();
        $paymentRequest = $this->paymentRequest(PaymentRequestInterface::STATE_COMPLETED, AutopayTransaction::STATUS_PENDING);

        self::assertTrue($provider->supports(new Request(), $paymentRequest));
        self::assertSame('/en_US/order/thank-you', $provider->getResponse(new Request(), $paymentRequest)->getTargetUrl());
    }

    #[TestWith([AutopayTransaction::STATUS_SUCCESS])]
    #[TestWith([AutopayTransaction::STATUS_FAILURE])]
    #[TestWith([null])]
    public function test_finished_or_unknown_status_is_left_to_sylius(?string $status): void
    {
        self::assertFalse($this->provider()->supports(new Request(), $this->paymentRequest(PaymentRequestInterface::STATE_COMPLETED, $status)));
    }

    #[TestWith([PaymentRequestInterface::STATE_NEW])]
    #[TestWith([PaymentRequestInterface::STATE_PROCESSING])]
    #[TestWith([PaymentRequestInterface::STATE_FAILED])]
    public function test_request_that_is_not_completed_is_left_to_sylius(string $state): void
    {
        self::assertFalse($this->provider()->supports(new Request(), $this->paymentRequest($state, AutopayTransaction::STATUS_PENDING)));
    }

    private function provider(): StatusHttpResponseProvider
    {
        $finalUrlProvider = self::createStub(FinalUrlProviderInterface::class);
        $finalUrlProvider->method('getUrl')->willReturn('/en_US/order/thank-you');

        return new StatusHttpResponseProvider($finalUrlProvider);
    }

    private function paymentRequest(string $state, ?string $status): PaymentRequestInterface
    {
        $paymentRequest = self::createStub(PaymentRequestInterface::class);
        $paymentRequest->method('getState')->willReturn($state);
        $paymentRequest->method('getResponseData')->willReturn(null === $status ? [] : ['autopay_status' => $status]);

        return $paymentRequest;
    }
}
