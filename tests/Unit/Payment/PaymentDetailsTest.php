<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Payment;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\AutopayGateway;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Sylius\Component\Core\Model\Payment;

final class PaymentDetailsTest extends TestCase
{
    public function test_orders_are_stored_under_the_plugin_key_and_keep_other_details(): void
    {
        $payment = new Payment();
        $payment->setDetails(['other' => 'kept']);

        $details = PaymentDetails::of($payment);
        $details->addOrder('O1', '100001', 'R1', 1500, 'PLN', 'AUTOPAY');
        $details->save();

        self::assertSame('kept', $payment->getDetails()['other']);
        self::assertSame('AUTOPAY', $details->getMethodCode('O1'));
        self::assertSame([
            'orders' => ['O1' => ['service_id' => '100001', 'method_code' => 'AUTOPAY', 'remote_id' => 'R1', 'status' => 'PENDING', 'amount' => 1500, 'currency' => 'PLN']],
            'current_order_id' => 'O1',
        ], $payment->getDetails()[AutopayGateway::DETAILS_KEY]);
    }

    public function test_orders_are_known_per_service(): void
    {
        $details = self::details();

        self::assertTrue($details->hasOrder('O1'));
        self::assertTrue($details->hasOrderOfService('O1', '100001'));
        self::assertFalse($details->hasOrderOfService('O1', '999'));
        self::assertFalse($details->hasOrder('O3'));
        self::assertFalse($details->hasOrderOfService('O3', '100001'));
        self::assertSame('O2', $details->getCurrentOrderId());
        self::assertSame(['O2', 'O1'], $details->getOpenOrderIds());
        self::assertTrue($details->hasOpenOrders());
    }

    public function test_final_order_status_is_never_overwritten(): void
    {
        $details = self::details();

        $details->recordOrderStatus('O1', 'SUCCESS', 'R1b');
        $details->recordOrderStatus('O1', 'FAILURE', 'R1c');
        $details->recordOrderStatus('O2', 'FAILURE');
        $details->recordOrderStatus('O3', 'SUCCESS');

        self::assertSame('SUCCESS', $details->getOrderStatus('O1'));
        self::assertSame('R1b', $details->getRemoteId('O1'));
        self::assertSame('FAILURE', $details->getOrderStatus('O2'));
        self::assertSame('R2', $details->getRemoteId('O2'));
        self::assertNull($details->getOrderStatus('O3'));
        self::assertSame([], $details->getOpenOrderIds());
        self::assertFalse($details->hasOpenOrders());
        // A failed OrderID can still be paid with a new attempt.
        self::assertSame(['O2'], $details->getUnpaidOrderIds());
        $details->recordOrderStatus('O2', 'SUCCESS', 'R2b');
        self::assertSame('SUCCESS', $details->getOrderStatus('O2'));
        self::assertSame([], $details->getUnpaidOrderIds());
    }

    public function test_warnings_are_recorded_once(): void
    {
        $details = self::details();

        $details->addWarning('a', 'O1');
        $details->addWarning('a', 'O1');
        $details->addWarning('a', 'O2');
        $details->addWarning('b', 'O1');

        self::assertSame([['type' => 'a', 'order_id' => 'O1'], ['type' => 'a', 'order_id' => 'O2'], ['type' => 'b', 'order_id' => 'O1']], $details->warnings());
    }

    public function test_refund_amounts_and_statuses(): void
    {
        $details = self::details();
        self::assertFalse($details->hasActiveRefund());

        $details->recordRefund('M1', 'R1', 'PENDING', 1000, 'PLN');
        $details->recordRefund('M2', 'R1', 'PENDING', 300, 'PLN');
        $details->recordRefund('M3', 'R1', 'PENDING', 200, 'PLN');

        self::assertTrue($details->updateRefundStatus('M1', 'DONE'));
        self::assertTrue($details->updateRefundStatus('M2', 'CANCELED'));
        self::assertFalse($details->updateRefundStatus('M3', 'PENDING'));
        self::assertFalse($details->updateRefundStatus('M1', 'CANCELED'));
        self::assertFalse($details->updateRefundStatus('M9', 'DONE'));

        self::assertSame('DONE', $details->refunds()['M1']['status']);
        self::assertSame(['M3'], $details->getPendingRefundIds());
        self::assertSame(1000, $details->finalizedRefundAmount());
        self::assertSame(1200, $details->activeRefundAmount());
        self::assertTrue($details->hasActiveRefund());
    }

    public function test_external_refunds_count_for_the_amount_but_not_as_requested_by_sylius(): void
    {
        $details = self::details();

        $key = $details->recordExternalRefund('ZWTR_1', 'DONE', 500, 'PLN');
        self::assertSame($key, $details->recordExternalRefund('ZWTR_1', 'PENDING', 999, 'PLN'));

        self::assertSame('autopay-ZWTR_1', $key);
        self::assertTrue($details->hasRefund($key));
        self::assertFalse($details->hasActiveRefund());
        self::assertSame(500, $details->activeRefundAmount());
        self::assertSame(500, $details->finalizedRefundAmount());
        self::assertSame([], $details->getPendingRefundIds());

        $details->recordRefund('M1', 'R1', 'PENDING', 1000, 'PLN');
        $details->setRefundRemoteOutId('M1', 'ZWTR_2');
        $details->setRefundRemoteOutId('M9', 'ZWTR_3');
        self::assertTrue($details->hasActiveRefund());
        self::assertSame('ZWTR_2', $details->refunds()['M1']['remote_out_id']);
        self::assertFalse($details->hasRefund('M9'));
    }

    public function test_canceled_refund_can_still_be_done_but_done_is_final(): void
    {
        $details = self::details();
        $details->recordRefund('M1', 'R1', 'REQUESTING', 1000, 'PLN');

        self::assertSame(['M1'], $details->getPendingRefundIds());
        self::assertSame(['remote_id' => 'R1', 'amount' => 1000, 'currency' => 'PLN', 'status' => 'REQUESTING'], $details->getRefund('M1'));
        self::assertTrue($details->updateRefundStatus('M1', 'CANCELED'));
        self::assertSame([], $details->getPendingRefundIds());
        self::assertTrue($details->updateRefundStatus('M1', 'DONE'));
        self::assertFalse($details->updateRefundStatus('M1', 'CANCELED'));
        self::assertSame(1000, $details->finalizedRefundAmount());
        self::assertNull($details->getRefund('M9'));
    }

    public function test_only_canceled_refunds_are_not_active(): void
    {
        $details = self::details();
        $details->recordRefund('M1', 'R1', 'CANCELED', 1000, 'PLN');

        self::assertFalse($details->hasActiveRefund());
        self::assertSame(0, $details->activeRefundAmount());
    }

    public function test_corrupted_data_is_ignored(): void
    {
        $payment = new Payment();
        $payment->setDetails([AutopayGateway::DETAILS_KEY => ['orders' => 'x', 'refunds' => ['M1' => 'x', 'M2' => ['amount' => '5', 'status' => 'DONE']], 'warnings' => ['x', ['type' => 'a']], 'current_order_id' => 5]]);
        $details = PaymentDetails::of($payment);

        self::assertFalse($details->hasOrder('O1'));
        self::assertNull($details->getCurrentOrderId());
        self::assertNull($details->getCompletedOrderId());
        self::assertSame(0, $details->finalizedRefundAmount());
        self::assertSame([['type' => 'a']], $details->warnings());
        self::assertCount(1, $details->refunds());

        $payment->setDetails([AutopayGateway::DETAILS_KEY => 'x']);
        self::assertSame([], PaymentDetails::of($payment)->refunds());
    }

    private static function details(): PaymentDetails
    {
        $details = PaymentDetails::of(new Payment());
        $details->addOrder('O1', '100001', 'R1', 1500, 'PLN');
        $details->addOrder('O2', '100001', 'R2', 1500, 'PLN');

        return $details;
    }
}
