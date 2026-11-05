<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Integration;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusAutopayPlugin\Support\FakeAutopay;
use function array_key_first;
use function parse_str;

/**
 * ISTN: Autopay notifies about refunds (also those made in its panel) when ISTN is enabled for the service.
 * Assumptions A2 and A3 (docs/autopay.md).
 */
final class RefundNotificationTest extends IntegrationTestCase
{
    public function test_istn_records_the_status_of_a_refund_requested_by_sylius(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->autopay->lastOrderId();
        $this->refundInAdmin($payment);
        $messageId = (string) array_key_first($this->autopay->refunds());

        $response = $this->notify($this->autopay->istnBody($orderId, 'ZWTR_1', $messageId, 1500, 'SUCCESS'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::confirmation('ZWTR_1'), $response->getContent());
        $refund = $this->details($payment)->refunds()[$messageId];
        self::assertSame('DONE', $refund['status']);
        self::assertSame('ZWTR_1', $refund['remote_out_id']);
        self::assertSame('refunded', $this->reloadPayment($payment)->getState());

        // The refresh command has nothing left to read.
        self::assertSame([], $this->details($payment)->getPendingRefundIds());
    }

    public function test_failed_refund_istn_marks_the_refund_canceled(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->autopay->lastOrderId();
        $this->refundInAdmin($payment);
        $messageId = (string) array_key_first($this->autopay->refunds());

        self::assertSame(Response::HTTP_OK, $this->notify($this->autopay->istnBody($orderId, 'ZWTR_1', $messageId, 1500, 'FAILURE'))->getStatusCode());

        self::assertSame('CANCELED', $this->details($payment)->refunds()[$messageId]['status']);
        self::assertFalse($this->details($payment)->hasActiveRefund());
    }

    public function test_full_refund_made_in_the_autopay_panel_marks_the_payment_refunded(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->autopay->lastOrderId();
        $remoteOutId = $this->autopay->panelRefund($orderId, 1500);
        $body = $this->autopay->istnBody($orderId, $remoteOutId, null, 1500, 'SUCCESS');

        self::assertSame(Response::HTTP_OK, $this->notify($body)->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->notify($body)->getStatusCode());

        self::assertSame('refunded', $this->reloadPayment($payment)->getState());
        $details = $this->details($payment);
        self::assertSame(1500, $details->finalizedRefundAmount());
        self::assertTrue($details->refunds()['autopay-' . $remoteOutId]['external'] ?? false);
        // Sylius must not ask Autopay for a second refund.
        self::assertSame([], $this->autopay->requests('POST', '#transactionRefund$#'));
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());
        self::assertTrue(self::at($requests[0]->getResponseData(), 'covered_by_autopay_panel_refunds'));
    }

    public function test_partial_panel_refund_is_recorded_and_an_admin_refund_asks_for_the_rest(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->autopay->lastOrderId();
        $remoteOutId = $this->autopay->panelRefund($orderId, 500);
        self::assertSame(Response::HTTP_OK, $this->notify($this->autopay->istnBody($orderId, $remoteOutId, null, 500, 'SUCCESS'))->getStatusCode());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());

        $this->refundInAdmin($payment);

        $calls = $this->autopay->requests('POST', '#transactionRefund$#');
        self::assertCount(1, $calls);
        parse_str($calls[0]['body'], $sent);
        self::assertSame('10.00', $sent['Amount'] ?? null);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND)[0]->getState());
    }

    public function test_settlement_istn_is_confirmed_and_ignored(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->autopay->lastOrderId();

        $response = $this->notify($this->autopay->istnBody($orderId, 'ROZL_1', 'ROZL_1', 1500, 'SUCCESS', false));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::confirmation('ROZL_1'), $response->getContent());
        self::assertCount(1, $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY));
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_istn_with_a_wrong_hash_is_rejected(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->autopay->lastOrderId();

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($this->autopay->istnBody($orderId, 'ZWTR_1', null, 1500, 'SUCCESS', sharedKey: 'attacker-key'))->getStatusCode());

        self::assertSame([], $this->details($payment)->refunds());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_refund_istn_that_does_not_match_the_paid_transaction_is_ignored(): void
    {
        $payment = $this->paidPayment();
        $orderId = $this->autopay->lastOrderId();

        self::assertSame(Response::HTTP_OK, $this->notify($this->autopay->istnBody($orderId, 'ZWTR_9', null, 1500, 'SUCCESS', currency: 'EUR'))->getStatusCode());

        self::assertSame([], $this->details($payment)->refunds());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    private function paidPayment(): PaymentInterface
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $this->autopay->setStatus($orderId, 'SUCCESS');
        $this->notifyOrder($orderId);
        self::assertSame('completed', $this->reloadPayment($payment)->getState());

        return $payment;
    }

    private function refundInAdmin(PaymentInterface $payment): void
    {
        $payment = $this->reloadPayment($payment);
        self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine')->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->em()->flush();
    }

    private static function confirmation(string $remoteOutId): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<confirmationList><serviceID>' . FakeAutopay::SERVICE_ID . '</serviceID>' .
            '<transactionsConfirmations><transactionConfirmed><remoteOutID>' . $remoteOutId . '</remoteOutID>' .
            '<confirmation>CONFIRMED</confirmation></transactionConfirmed></transactionsConfirmations>' .
            '<hash>' . FakeAutopay::sign(FakeAutopay::SERVICE_ID, $remoteOutId, 'CONFIRMED') . '</hash></confirmationList>';
    }
}
