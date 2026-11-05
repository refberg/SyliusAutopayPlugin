<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Notification;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Notification\Notification;
use Refberg\SyliusAutopayPlugin\Notification\NotificationParser;
use Refberg\SyliusAutopayPlugin\Signature\SignatureVerifier;
use Tests\Refberg\SyliusAutopayPlugin\Support\Credentials;
use Tests\Refberg\SyliusAutopayPlugin\Support\FakeAutopay;
use function array_values;
use function base64_encode;
use function http_build_query;

final class NotificationParserTest extends TestCase
{
    private const TRANSACTION = [
        'orderID' => 'ORDER-1',
        'remoteID' => 'R1',
        'amount' => '15.00',
        'currency' => 'PLN',
        'gatewayID' => '106',
        'paymentDate' => '20260101120000',
        'paymentStatus' => 'SUCCESS',
        'paymentStatusDetails' => 'AUTHORIZED',
    ];

    public function test_it_parses_a_signed_itn(): void
    {
        $notification = self::parser()->parse(FakeAutopay::itn(self::TRANSACTION), Credentials::fake());

        self::assertEquals(new Notification(Notification::TYPE_TRANSACTION, FakeAutopay::SERVICE_ID, 'ORDER-1', 'R1', 'SUCCESS'), $notification);
    }

    public function test_it_parses_a_signed_istn_about_a_refund(): void
    {
        $transaction = ['isRefund' => 'true', 'orderID' => 'ORDER-1', 'orderOutID' => 'M1', 'remoteID' => 'R1', 'remoteOutID' => 'ZWTR_1', 'amount' => '5.50', 'currency' => 'PLN', 'transferStatus' => 'SUCCESS'];

        $notification = self::parser()->parse(self::body(FakeAutopay::transactionListXml('100001', [$transaction], FakeAutopay::sign('100001', ...array_values($transaction)))), Credentials::fake());

        self::assertEquals(new Notification(Notification::TYPE_REFUND, '100001', 'ORDER-1', 'R1', 'SUCCESS', 'M1', 'ZWTR_1', 550, 'PLN'), $notification);
        self::assertTrue($notification?->isTransfer());
    }

    public function test_istn_without_refund_flag_is_a_settlement_and_needs_a_remote_out_id(): void
    {
        $settlement = ['isRefund' => 'false', 'remoteOutID' => 'ROZL_1', 'amount' => '100.00', 'currency' => 'PLN', 'transferStatus' => 'SUCCESS'];
        $withoutId = ['isRefund' => 'true', 'orderID' => 'ORDER-1', 'transferStatus' => 'SUCCESS'];

        $notification = self::parser()->parse(self::body(FakeAutopay::transactionListXml('100001', [$settlement], FakeAutopay::sign('100001', ...array_values($settlement)))), Credentials::fake());

        self::assertSame(Notification::TYPE_SETTLEMENT, $notification?->type);
        self::assertNull(self::parser()->parse(self::body(FakeAutopay::transactionListXml('100001', [$withoutId], FakeAutopay::sign('100001', ...array_values($withoutId)))), Credentials::fake()));
    }

    public function test_nested_customer_data_is_covered_by_the_hash(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><transactionList><serviceID>100001</serviceID><transactions><transaction>' .
            '<orderID>ORDER-1</orderID><remoteID>R1</remoteID><customerData><fName>Jan</fName><lName>Kowalski</lName></customerData>' .
            '</transaction></transactions><hash>' . FakeAutopay::sign('100001', 'ORDER-1', 'R1', 'Jan', 'Kowalski') . '</hash></transactionList>';

        $notification = self::parser()->parse(self::body($xml), Credentials::fake());

        self::assertInstanceOf(Notification::class, $notification);
        self::assertSame('ORDER-1', $notification->orderId);
    }

    public function test_itn_signed_with_another_key_is_rejected(): void
    {
        self::assertNull(self::parser()->parse(FakeAutopay::itn(self::TRANSACTION, 'other-key'), Credentials::fake()));
    }

    public function test_itn_of_another_service_is_rejected(): void
    {
        $body = FakeAutopay::itn(self::TRANSACTION, FakeAutopay::SHARED_KEY, '999');

        self::assertNull(self::parser()->parse($body, Credentials::fake()));
        self::assertNotNull(self::parser()->parse($body, Credentials::fake('999')));
    }

    public function test_itn_with_several_transactions_is_rejected(): void
    {
        $second = ['orderID' => 'ORDER-2'] + self::TRANSACTION;
        $values = ['100001', ...array_values(self::TRANSACTION), ...array_values($second)];
        $xml = FakeAutopay::transactionListXml('100001', [self::TRANSACTION, $second], FakeAutopay::sign(...$values));

        self::assertNull(self::parser()->parse(self::body($xml), Credentials::fake()));
    }

    public function test_malformed_itns_are_rejected(): void
    {
        $parser = self::parser();
        $credentials = Credentials::fake();
        $withoutOrder = self::TRANSACTION;
        unset($withoutOrder['orderID']);

        self::assertNull($parser->parse('', $credentials));
        self::assertNull($parser->parse('transactions=%%%', $credentials));
        self::assertNull($parser->parse(http_build_query(['transactions' => ['x']]), $credentials));
        self::assertNull($parser->parse(self::body('<other><serviceID>100001</serviceID><hash>' . FakeAutopay::sign('100001') . '</hash></other>'), $credentials));
        self::assertNull($parser->parse(FakeAutopay::itn($withoutOrder), $credentials));
        self::assertNull($parser->parse(self::body(FakeAutopay::transactionListXml('100001', [self::TRANSACTION], null)), $credentials));
    }

    public function test_it_parses_the_payload_stored_by_sylius(): void
    {
        $parser = self::parser();
        $body = FakeAutopay::itn(self::TRANSACTION);

        self::assertNotNull($parser->parseStoredPayload(['http_request' => ['content' => $body, 'headers' => []]], Credentials::fake()));
        self::assertNull($parser->parseStoredPayload(['http_request' => ['content' => null]], Credentials::fake()));
        self::assertNull($parser->parseStoredPayload(['http_request' => 'x'], Credentials::fake()));
        self::assertNull($parser->parseStoredPayload(null, Credentials::fake()));
    }

    private static function parser(): NotificationParser
    {
        return new NotificationParser(new SignatureVerifier());
    }

    private static function body(string $xml): string
    {
        return http_build_query(['transactions' => base64_encode($xml)]);
    }
}
