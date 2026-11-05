<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Integration;

use Refberg\SyliusAutopayPlugin\Console\RefreshRefundsCommand;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use function array_key_first;
use function array_key_last;
use function array_map;
use function array_values;
use function json_encode;
use function parse_str;
use function str_replace;

final class RefundFlowTest extends IntegrationTestCase
{
    public function test_admin_refund_requests_a_single_full_refund_at_autopay(): void
    {
        $payment = $this->paidPayment();
        $remoteId = $this->autopay->transaction($this->autopay->lastOrderId())['attempts'][0]['remoteID'];

        $this->refundInAdmin($payment);

        $calls = $this->autopay->requests('POST', '#^/settlementapi/transactionRefund$#');
        self::assertCount(1, $calls);
        parse_str($calls[0]['body'], $sent);
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        $messageId = str_replace('-', '', (string) $requests[0]->getId());
        self::assertSame([
            'ServiceID' => '100001',
            'MessageID' => $messageId,
            'RemoteID' => $remoteId,
            'Amount' => '15.00',
            'Currency' => 'PLN',
        ], [
            'ServiceID' => $sent['ServiceID'] ?? null,
            'MessageID' => $sent['MessageID'] ?? null,
            'RemoteID' => $sent['RemoteID'] ?? null,
            'Amount' => $sent['Amount'] ?? null,
            'Currency' => $sent['Currency'] ?? null,
        ]);
        self::assertSame(PaymentRequestInterface::STATE_COMPLETED, $requests[0]->getState());

        $payment = $this->reloadPayment($payment);
        self::assertSame('refunded', $payment->getState());
        $refunds = PaymentDetails::of($payment)->refunds();
        self::assertEquals(['remote_id' => $remoteId, 'status' => 'PENDING', 'amount' => 1500, 'currency' => 'PLN'], $refunds[$messageId]);
    }

    public function test_redelivered_refund_request_does_not_refund_twice(): void
    {
        $payment = $this->paidPayment();
        $this->refundInAdmin($payment);

        $this->dispatchPaymentRequest($payment, PaymentRequestInterface::ACTION_REFUND);

        self::assertCount(1, $this->autopay->requests('POST', '#^/settlementapi/transactionRefund$#'));
        self::assertCount(1, $this->autopay->refunds());
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(2, $requests);
        self::assertTrue(self::at($requests[1]->getResponseData(), 'already_requested'));
    }

    public function test_refresh_command_records_the_final_refund_status(): void
    {
        $payment = $this->paidPayment();
        $this->refundInAdmin($payment);
        $messageId = (string) array_key_first($this->autopay->refunds());

        $this->autopay->setRefundStatus($messageId, 'PROCESSING');
        self::assertSame(Command::SUCCESS, $this->refreshRefunds()->getStatusCode());
        self::assertSame('PENDING', $this->details($payment)->refunds()[$messageId]['status']);

        $this->autopay->setRefundStatus($messageId, 'DONE');
        $tester = $this->refreshRefunds();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Autopay refunds updated: 1.', $tester->getDisplay());
        self::assertSame('DONE', $this->details($payment)->refunds()[$messageId]['status']);
        self::assertSame(1500, $this->details($payment)->finalizedRefundAmount());

        // A final status is not read again.
        $this->refreshRefunds();
        self::assertCount(2, $this->autopay->requests('POST', '#^/settlementapi/outDetails/v2$#'));
        self::assertSame('refunded', $this->reloadPayment($payment)->getState());
    }

    public function test_refund_canceled_by_autopay_is_recorded_and_the_next_admin_refund_can_request_it_again(): void
    {
        $payment = $this->paidPayment();
        $this->refundInAdmin($payment);
        $messageId = (string) array_key_first($this->autopay->refunds());
        $this->autopay->setRefundStatus($messageId, 'CANCELED_NO_FUNDS_ON_BALANCE');

        $this->refreshRefunds();

        $payment = $this->reloadPayment($payment);
        self::assertSame('refunded', $payment->getState());
        $details = PaymentDetails::of($payment);
        self::assertSame('CANCELED', $details->refunds()[$messageId]['status']);
        self::assertFalse($details->hasActiveRefund());
        self::assertSame(0, $details->activeRefundAmount());
    }

    public function test_refund_refused_by_autopay_is_recorded_as_canceled(): void
    {
        $payment = $this->paidPayment();
        $this->autopay->failNextRequest(200, 'TRANSACTION_TOO_OLD_TO_REFUND');

        $this->refundInAdmin($payment);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertStringContainsString('TRANSACTION_TOO_OLD_TO_REFUND', (string) json_encode($requests[0]->getResponseData()));
        $details = $this->details($payment);
        self::assertSame(['CANCELED'], array_values(array_map(static fn (array $refund): mixed => $refund['status'] ?? null, $details->refunds())));
        self::assertSame([], $details->getPendingRefundIds());
        self::assertSame('refunded', $this->reloadPayment($payment)->getState());
    }

    public function test_refund_that_did_not_reach_autopay_is_requested_again_by_the_refresh_command(): void
    {
        $payment = $this->paidPayment();
        $this->autopay->failNextRequest(503, 'SYSTEM_ERROR');

        $this->refundInAdmin($payment);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        $messageId = str_replace('-', '', (string) $requests[0]->getId());
        self::assertSame('REQUESTING', $this->details($payment)->refunds()[$messageId]['status']);
        self::assertSame([], $this->autopay->refunds());

        self::assertSame(Command::SUCCESS, $this->refreshRefunds()->getStatusCode());

        self::assertArrayHasKey($messageId, $this->autopay->refunds());
        self::assertSame('PENDING', $this->details($payment)->refunds()[$messageId]['status']);
        // Requested again with the same MessageID: still one refund at Autopay.
        $this->refreshRefunds();
        self::assertCount(1, $this->autopay->refunds());
    }

    public function test_refresh_command_goes_on_after_a_failing_payment(): void
    {
        $first = $this->paidPayment();
        $this->refundInAdmin($first);
        $firstMessageId = (string) array_key_first($this->autopay->refunds());
        // The refund of the first payment is unknown to Autopay: reading its status fails.
        $this->autopay->forgetRefund($firstMessageId);

        $second = $this->paidPayment('000000002', 'order-token-2');
        $this->refundInAdmin($second);
        $secondMessageId = (string) array_key_last($this->autopay->refunds());
        $this->autopay->setRefundStatus($secondMessageId, 'DONE');

        $tester = $this->refreshRefunds();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Autopay refunds updated: 1.', $tester->getDisplay());
        self::assertSame('DONE', $this->details($second)->refunds()[$secondMessageId]['status']);
        self::assertSame('PENDING', $this->details($first)->refunds()[$firstMessageId]['status']);
    }

    public function test_refund_request_for_a_payment_that_was_not_refunded_in_sylius_never_reaches_autopay(): void
    {
        $payment = $this->paidPayment();

        $this->dispatchPaymentRequest($payment, PaymentRequestInterface::ACTION_REFUND);

        self::assertSame([], $this->autopay->requests('POST', '#^/settlementapi/transactionRefund$#'));
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertCount(1, $requests);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertSame(['error' => 'Payment in state "completed" cannot be refunded.'], $requests[0]->getResponseData());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    /**
     * Without ISTN the plugin does not know about refunds made in the Autopay panel,
     * and Autopay refuses a refund larger than the remaining amount.
     */
    public function test_admin_refund_after_a_panel_refund_fails_and_is_left_to_the_merchant(): void
    {
        $payment = $this->paidPayment();
        $this->autopay->panelRefund($this->autopay->lastOrderId(), 500);

        $this->refundInAdmin($payment);

        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertStringContainsString('AMOUNT_EXCEEDS_BALANCE', (string) json_encode($requests[0]->getResponseData()));
        self::assertFalse($this->details($payment)->hasActiveRefund());
    }

    private function paidPayment(string $number = '000000001', string $token = 'order-token'): PaymentInterface
    {
        $payment = '000000001' === $number ? $this->createPlacedOrder() : $this->createSecondPlacedOrder($number, $token);
        $this->payInShop($token);
        $orderId = $this->autopay->lastOrderId();
        $this->autopay->setStatus($orderId, 'SUCCESS');
        $this->notifyOrder($orderId);

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());

        return $payment;
    }

    /**
     * Same transition the admin "Refund" button applies.
     */
    private function refundInAdmin(PaymentInterface $payment): void
    {
        $payment = $this->reloadPayment($payment);
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');

        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->em()->flush();
    }

    private function refreshRefunds(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find(RefreshRefundsCommand::NAME));
        $tester->execute([]);

        return $tester;
    }
}
