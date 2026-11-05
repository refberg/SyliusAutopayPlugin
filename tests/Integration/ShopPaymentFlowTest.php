<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Response;
use Tests\Refberg\SyliusAutopayPlugin\Support\FakeAutopay;
use function base64_encode;
use function http_build_query;
use function str_replace;

final class ShopPaymentFlowTest extends IntegrationTestCase
{
    public function test_customer_is_redirected_to_autopay_with_server_side_amount(): void
    {
        $payment = $this->createPlacedOrder();

        $redirect = $this->payInShop();

        $orderId = $this->autopay->lastOrderId();
        $transaction = $this->autopay->transaction($orderId);
        self::assertSame('https://' . FakeAutopay::HOST . '/payment/continue/' . $transaction['attempts'][0]['remoteID'] . '/ABCDEFGH', $redirect);

        $fields = $transaction['fields'];
        self::assertSame(FakeAutopay::SERVICE_ID, $fields['ServiceID']);
        self::assertSame('15.00', $fields['Amount']);
        self::assertSame('PLN', $fields['Currency']);
        self::assertSame('Order 000000001', $fields['Description']);
        self::assertSame('buyer@example.com', $fields['CustomerEmail']);
        self::assertMatchesRegularExpression('#^http://localhost/en_US/order/after-pay/[0-9a-f-]{36}$#', $fields['ReturnURL']);
        $paymentId = $payment->getId();
        self::assertIsInt($paymentId);
        self::assertMatchesRegularExpression('#^000000001-' . $paymentId . '-[0-9a-f]{8}$#', $orderId);

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        $details = $this->details($payment);
        self::assertSame($orderId, $details->getCurrentOrderId());
        self::assertTrue($details->hasOrderOfService($orderId, FakeAutopay::SERVICE_ID));
    }

    public function test_opening_the_pay_page_again_reuses_the_autopay_transaction(): void
    {
        $this->createPlacedOrder();

        $first = $this->payInShop();
        $second = $this->payInShop();

        self::assertSame($first, $second);
        self::assertCount(1, $this->autopay->orderIds());
    }

    public function test_returning_from_autopay_does_not_pay_the_order_by_itself(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();

        // The return URL can be opened by anyone, with any parameters: it is not proof of payment.
        $this->client->request('GET', $this->returnPath($orderId) . '?' . http_build_query(['ServiceID' => FakeAutopay::SERVICE_ID, 'OrderID' => $orderId, 'Hash' => FakeAutopay::sign(FakeAutopay::SERVICE_ID, $orderId)]));

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertCount(1, $this->autopay->requests('POST', '#^/webapi/transactionStatus$#'));
    }

    public function test_returning_while_autopay_is_processing_shows_thank_you_page_and_keeps_payment_unpaid(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();

        $this->client->request('GET', $this->returnPath($orderId));

        self::assertStringContainsString('/order/thank-you', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_returning_after_autopay_confirmed_the_payment_marks_it_paid(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $this->autopay->setStatus($orderId, 'SUCCESS');

        $this->client->request('GET', $this->returnPath($orderId));

        self::assertStringContainsString('/order/thank-you', (string) $this->client->getResponse()->headers->get('Location'));
        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame('paid', $payment->getOrder()?->getPaymentState());
    }

    public function test_signed_success_itn_pays_the_order_and_is_confirmed(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $this->autopay->setStatus($orderId, 'SUCCESS');

        $response = $this->notifyOrder($orderId);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('application/xml', (string) $response->headers->get('Content-Type'));
        self::assertSame(self::confirmation($orderId, 'CONFIRMED'), $response->getContent());
        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame('paid', $payment->getOrder()?->getPaymentState());
        $details = PaymentDetails::of($payment);
        self::assertSame($orderId, $details->getCompletedOrderId());
        self::assertSame($this->autopay->transaction($orderId)['attempts'][0]['remoteID'], $details->getRemoteId($orderId));
    }

    public function test_duplicated_itns_are_confirmed_and_change_nothing(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $this->autopay->setStatus($orderId, 'SUCCESS');

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
        }

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame([], PaymentDetails::of($payment)->warnings());
        self::assertCount(3, $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY));
    }

    public function test_itn_with_wrong_hash_or_body_is_rejected_and_not_stored(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $this->autopay->setStatus($orderId, 'SUCCESS');

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($this->autopay->itnBody($orderId, sharedKey: 'attacker-key'))->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify('')->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify('transactions=not-base64!')->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify(http_build_query(['transactions' => base64_encode('<transactionList><x>')]))->getStatusCode());

        $valid = $this->autopay->itnBody($orderId, 'PENDING');
        $tampered = http_build_query(['transactions' => base64_encode(str_replace('PENDING', 'SUCCESS', FakeAutopay::transactionListXml(FakeAutopay::SERVICE_ID, [['orderID' => $orderId, 'paymentStatus' => 'PENDING']], FakeAutopay::sign(FakeAutopay::SERVICE_ID, $orderId, 'PENDING'))))]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($tampered)->getStatusCode());
        self::assertNotSame($valid, $tampered);

        // Signed with the right key, but for another service.
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($this->autopay->itnBody($orderId, serviceId: '999999'))->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertCount(0, $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY));
        self::assertCount(0, $this->autopay->requests('POST', '#^/webapi/transactionStatus$#'));
    }

    public function test_itn_with_external_entities_is_rejected(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();

        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><transactionList><serviceID>' . FakeAutopay::SERVICE_ID . '</serviceID><transactions><transaction><orderID>' . $orderId . '</orderID><remoteID>&e;</remoteID></transaction></transactions><hash>x</hash></transactionList>';

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify(http_build_query(['transactions' => base64_encode($xml)]))->getStatusCode());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_itn_for_an_unknown_order_id_is_rejected(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $paymentId = $payment->getId();
        self::assertIsInt($paymentId);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->notify(FakeAutopay::itn(['orderID' => 'FOREIGN', 'paymentStatus' => 'SUCCESS']))->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->notify(FakeAutopay::itn(['orderID' => '000000001-' . $paymentId . '-00000000', 'paymentStatus' => 'SUCCESS']))->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_itn_for_a_method_without_credentials_returns_503_so_autopay_retries(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $this->autopay->setStatus($orderId, 'SUCCESS');
        $this->createAutopayMethod('AUTOPAY2', FakeAutopay::SERVICE_ID, FakeAutopay::SHARED_KEY)->getGatewayConfig()?->setConfig(['environment' => 'sandbox']);
        $this->em()->flush();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->notify($this->autopay->itnBody($orderId), 'AUTOPAY2')->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
        self::assertCount(0, $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mismatches(): iterable
    {
        yield 'amount' => ['amount', '0.01'];
        yield 'currency' => ['currency', 'EUR'];
    }

    #[DataProvider('mismatches')]
    public function test_transaction_that_does_not_match_the_payment_never_pays_it(string $field, string $value): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $body = $this->autopay->itnBody($orderId, 'SUCCESS');
        $this->autopay->setStatus($orderId, 'SUCCESS');
        $this->autopay->overrideTransaction($orderId, $field, $value);

        $response = $this->notify($body);

        // Processed for good: confirmed, so Autopay stops repeating it.
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::confirmation($orderId, 'CONFIRMED'), $response->getContent());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_itn_status_is_not_trusted(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();

        // Correctly signed, claims SUCCESS, but the Autopay API still reports PENDING.
        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId, 'SUCCESS')->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    public function test_late_pending_and_failure_itns_do_not_undo_the_payment(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $this->autopay->setStatus($orderId, 'SUCCESS');
        $this->notifyOrder($orderId);

        $this->autopay->addAttempt($orderId, 'FAILURE');
        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId, 'PENDING')->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId, 'FAILURE')->getStatusCode());

        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_failed_transaction_fails_the_payment(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $this->autopay->setStatus($orderId, 'FAILURE');

        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());

        self::assertSame('failed', $this->reloadPayment($payment)->getState());
    }

    public function test_successful_retry_on_the_autopay_page_after_a_failed_attempt_pays_the_order(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $this->autopay->setStatus($orderId, 'FAILURE');
        $remoteId = $this->autopay->addAttempt($orderId, 'SUCCESS');

        // ITNs of both attempts arrive, the failed one last.
        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId, 'SUCCESS')->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId, 'FAILURE')->getStatusCode());

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame($remoteId, PaymentDetails::of($payment)->getRemoteId($orderId));
    }

    public function test_two_paid_transactions_never_pay_the_sylius_order_twice(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $firstOrderId = $this->autopay->lastOrderId();

        // The first attempt is abandoned; the customer starts a new one.
        $this->finalizeCaptureRequests(PaymentRequestTransitions::TRANSITION_FAIL);
        $this->payInShop();
        $secondOrderId = $this->autopay->lastOrderId();
        self::assertNotSame($firstOrderId, $secondOrderId);

        $this->autopay->setStatus($secondOrderId, 'SUCCESS');
        $this->notifyOrder($secondOrderId);
        $this->autopay->setStatus($firstOrderId, 'SUCCESS');
        self::assertSame(Response::HTTP_OK, $this->notifyOrder($firstOrderId)->getStatusCode());

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        $details = PaymentDetails::of($payment);
        self::assertSame($secondOrderId, $details->getCompletedOrderId());
        self::assertSame([['type' => PaymentDetails::WARNING_DUPLICATE_PAYMENT, 'order_id' => $firstOrderId]], $details->warnings());
    }

    /**
     * A2, A4 (docs/autopay.md).
     */
    #[TestWith([503, 'SYSTEM_ERROR'])]
    #[TestWith([200, 'INVALID_HASH'])]
    #[TestWith([401, 'UNAUTHORIZED'])]
    #[TestWith([403, 'FORBIDDEN'])]
    public function test_transient_or_authorization_api_error_returns_5xx_so_autopay_retries(int $httpStatus, string $errorName): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $this->autopay->setStatus($orderId, 'SUCCESS');
        $this->autopay->failNextRequest($httpStatus, $errorName);

        $this->client->catchExceptions(true);
        self::assertGreaterThanOrEqual(500, $this->notifyOrder($orderId)->getStatusCode());
        self::assertSame('new', $this->reloadPayment($payment)->getState());

        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    /**
     * A4 (docs/autopay.md).
     */
    public function test_permanent_api_error_is_confirmed_and_recorded(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $this->autopay->failNextRequest(200, 'TRANSACTION_NOT_FOUND');

        $response = $this->notifyOrder($orderId);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(self::confirmation($orderId, 'CONFIRMED'), $response->getContent());
        $requests = $this->paymentRequests(PaymentRequestInterface::ACTION_NOTIFY);
        self::assertSame(PaymentRequestInterface::STATE_FAILED, $requests[0]->getState());
        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    /**
     * A7 (docs/autopay.md).
     */
    #[TestWith(['notification'])]
    #[TestWith(['return'])]
    public function test_payment_made_at_autopay_after_the_customer_switched_payment_method_completes_it_with_a_warning(string $confirmedBy): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $payment = $this->switchToBankTransfer($payment);

        $this->autopay->setStatus($orderId, 'SUCCESS');
        if ('notification' === $confirmedBy) {
            self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
            $this->client->request('GET', $this->returnPath($orderId));
            self::assertTrue($this->client->getResponse()->isRedirect());
        } else {
            $this->client->request('GET', $this->returnPath($orderId));
            self::assertTrue($this->client->getResponse()->isRedirect());
            self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
        }

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame(self::METHOD_CODE, $payment->getMethod()?->getCode());
        $details = PaymentDetails::of($payment);
        self::assertSame($orderId, $details->getCompletedOrderId());
        self::assertSame([['type' => PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, 'order_id' => $orderId]], $details->warnings());

        // The restored Autopay method lets an admin refund reach Autopay.
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->em()->flush();

        self::assertCount(1, $this->autopay->requests('POST', '#^/settlementapi/transactionRefund$#'));
    }

    #[TestWith(['notification'])]
    #[TestWith(['return'])]
    public function test_pending_transaction_after_the_customer_switched_payment_method_keeps_the_new_method(string $checkedBy): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $this->switchToBankTransfer($payment);

        if ('notification' === $checkedBy) {
            self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
        } else {
            $this->client->request('GET', $this->returnPath($orderId));
            self::assertTrue($this->client->getResponse()->isRedirect());
        }

        $payment = $this->reloadPayment($payment);
        self::assertSame('new', $payment->getState());
        self::assertSame('BANK_TRANSFER', $payment->getMethod()?->getCode());
        self::assertSame([], PaymentDetails::of($payment)->warnings());
    }

    public function test_payment_made_in_the_old_transaction_after_switching_to_another_autopay_service_is_refunded_with_the_paid_method(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();

        $payment = $this->reloadPayment($payment);
        $payment->setMethod($this->createAutopayMethod('AUTOPAY2', '999999', 'other-shared-key'));
        $this->em()->flush();
        $this->autopay->setStatus($orderId, 'SUCCESS');

        // The return from Autopay checks the status with the method of the capture request.
        $this->client->request('GET', $this->returnPath($orderId));
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());

        $payment = $this->reloadPayment($payment);
        self::assertSame('completed', $payment->getState());
        self::assertSame(self::METHOD_CODE, $payment->getMethod()?->getCode());
        self::assertSame([['type' => PaymentDetails::WARNING_PAYMENT_METHOD_CHANGED, 'order_id' => $orderId]], PaymentDetails::of($payment)->warnings());

        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
        $this->em()->flush();

        self::assertSame(self::METHOD_CODE, $this->paymentRequests(PaymentRequestInterface::ACTION_REFUND)[0]->getMethod()->getCode());
        self::assertCount(1, $this->autopay->requests('POST', '#^/settlementapi/transactionRefund$#'));
    }

    public function test_api_status_check_with_another_autopay_service_leaves_the_old_transaction_alone(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $payment = $this->reloadPayment($payment);
        $method = $this->createAutopayMethod('AUTOPAY2', '999999', 'other-shared-key');
        $channel = $payment->getOrder()?->getChannel();
        self::assertNotNull($channel);
        $method->addChannel($channel);
        $payment->setMethod($method);
        $this->em()->flush();
        $this->autopay->setStatus($orderId, 'SUCCESS');

        $status = $this->postPaymentRequest(['paymentId' => $payment->getId(), 'paymentMethodCode' => 'AUTOPAY2', 'action' => 'status']);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        // The old transaction cannot be read with the new service's credentials; its ITN still completes the payment.
        self::assertSame('completed', $status['state']);
        self::assertSame('new', self::at($status, 'responseData', 'payment_state'));
        self::assertCount(0, $this->autopay->requests('POST', '#^/webapi/transactionStatus$#'));
        self::assertSame(Response::HTTP_OK, $this->notifyOrder($orderId)->getStatusCode());
        self::assertSame('completed', $this->reloadPayment($payment)->getState());
    }

    public function test_itn_of_another_autopay_service_never_pays_the_payment(): void
    {
        $payment = $this->createPlacedOrder();
        $this->payInShop();
        $orderId = $this->autopay->lastOrderId();
        $this->createAutopayMethod('AUTOPAY2', '999999', 'other-shared-key');
        $this->em()->flush();
        $this->autopay->setStatus($orderId, 'SUCCESS');

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->notify($this->autopay->itnBody($orderId), 'AUTOPAY2')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->notify($this->autopay->itnBody($orderId, sharedKey: 'other-shared-key', serviceId: '999999'), 'AUTOPAY2')->getStatusCode());

        self::assertSame('new', $this->reloadPayment($payment)->getState());
    }

    private function switchToBankTransfer(PaymentInterface $payment): PaymentInterface
    {
        $bankTransfer = $this->factory('payment_method')->createNew();
        self::assertInstanceOf(PaymentMethodInterface::class, $bankTransfer);
        $bankTransfer->setCode('BANK_TRANSFER');
        $bankTransfer->setCurrentLocale('en_US');
        $bankTransfer->setFallbackLocale('en_US');
        $bankTransfer->setName('Bank transfer');
        $payment = $this->reloadPayment($payment);
        $payment->setMethod($bankTransfer);
        $this->em()->persist($bankTransfer);
        $this->em()->flush();

        return $payment;
    }

    private function finalizeCaptureRequests(string $transition): void
    {
        $stateMachine = self::service(StateMachineInterface::class, 'sylius_abstraction.state_machine');

        foreach ($this->paymentRequests(PaymentRequestInterface::ACTION_CAPTURE) as $request) {
            if ($stateMachine->can($request, PaymentRequestTransitions::GRAPH, $transition)) {
                $stateMachine->apply($request, PaymentRequestTransitions::GRAPH, $transition);
            }
        }
        $this->em()->flush();
    }

    private static function confirmation(string $orderId, string $confirmation): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<confirmationList><serviceID>' . FakeAutopay::SERVICE_ID . '</serviceID>' .
            '<transactionsConfirmations><transactionConfirmed><orderID>' . $orderId . '</orderID>' .
            '<confirmation>' . $confirmation . '</confirmation></transactionConfirmed></transactionsConfirmations>' .
            '<hash>' . FakeAutopay::sign(FakeAutopay::SERVICE_ID, $orderId, $confirmation) . '</hash></confirmationList>';
    }
}
