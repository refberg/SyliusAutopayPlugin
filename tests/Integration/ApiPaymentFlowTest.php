<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Integration;

use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusAutopayPlugin\Support\FakeAutopay;

/**
 * Headless checkout through Sylius API Platform endpoints, without any shop UI.
 */
final class ApiPaymentFlowTest extends IntegrationTestCase
{
    public function test_api_client_gets_the_autopay_redirect_url_and_checks_status(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'payload' => ['return_url' => 'https://app.example/payment/return'],
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame('capture', $created['action']);
        self::assertSame('processing', $created['state']);
        $orderId = $this->autopay->lastOrderId();
        $remoteId = $this->autopay->transaction($orderId)['attempts'][0]['remoteID'];
        self::assertSame('https://' . FakeAutopay::HOST . '/payment/continue/' . $remoteId . '/ABCDEFGH', self::at($created, 'responseData', 'redirect_url'));
        self::assertSame('https://app.example/payment/return', $this->autopay->transaction($orderId)['fields']['ReturnURL']);

        $this->autopay->setStatus($orderId, 'SUCCESS');
        $status = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'action' => 'status',
        ]);

        self::assertSame('completed', $status['state']);
        self::assertSame('completed', self::at($status, 'responseData', 'payment_state'));
        self::assertSame('SUCCESS', self::at($status, 'responseData', 'autopay_status'));
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_invalid_return_url_fails_the_request_without_calling_autopay(): void
    {
        $payment = $this->createPlacedOrder();

        $created = $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'payload' => ['return_url' => 'javascript:alert(1)'],
        ]);

        self::assertSame('failed', $created['state']);
        self::assertSame([], $this->autopay->orderIds());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_autopay_rejecting_the_transaction_fails_the_request(): void
    {
        $payment = $this->createPlacedOrder();
        $this->autopay->failNextRequest(200, 'INVALID_HASH');

        $created = $this->postPaymentRequest(['paymentId' => $payment->getId(), 'paymentMethodCode' => self::METHOD_CODE]);

        self::assertSame('failed', $created['state']);
        self::assertIsString(self::at($created, 'responseData', 'error'));
        self::assertStringContainsString('INVALID_HASH', self::at($created, 'responseData', 'error'));
    }

    public function test_refund_action_is_not_available_to_shop_api_clients(): void
    {
        $payment = $this->createPlacedOrder();

        $this->postPaymentRequest([
            'paymentId' => $payment->getId(),
            'paymentMethodCode' => self::METHOD_CODE,
            'action' => 'refund',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->autopay->requests('POST', '#^/settlementapi/transactionRefund$#'));
    }
}
