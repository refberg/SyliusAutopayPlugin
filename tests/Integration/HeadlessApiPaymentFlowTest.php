<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Integration;

use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusAutopayPlugin\HeadlessApplication\HeadlessKernel;

/**
 * Sylius without the shop, the admin panel and Twig Hooks.
 */
final class HeadlessApiPaymentFlowTest extends IntegrationTestCase
{
    public function test_kernel_runs_without_shop_admin_and_twig_hooks(): void
    {
        $bundles = self::getContainer()->getParameter('kernel.bundles');
        self::assertArrayNotHasKey('SyliusShopBundle', $bundles);
        self::assertArrayNotHasKey('SyliusAdminBundle', $bundles);
        self::assertArrayNotHasKey('SyliusTwigHooksBundle', $bundles);
    }

    public function test_api_checkout_is_paid_by_a_signed_itn_without_the_shop(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'payload' => ['return_url' => 'https://app.example/payment/return'],
        ]);

        self::assertSame('processing', $created['state'], (string) $this->client->getResponse()->getContent());
        $orderId = $this->autopay->lastOrderId();
        $this->autopay->setStatus($orderId, 'SUCCESS');

        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_capture_without_return_url_fails_without_calling_autopay(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
        ]);

        self::assertSame('failed', $created['state']);
        self::assertIsString(self::at($created, 'responseData', 'error'));
        self::assertStringContainsString('return_url', self::at($created, 'responseData', 'error'));
        self::assertSame([], $this->autopay->orderIds());
    }

    protected static function getKernelClass(): string
    {
        return HeadlessKernel::class;
    }
}
