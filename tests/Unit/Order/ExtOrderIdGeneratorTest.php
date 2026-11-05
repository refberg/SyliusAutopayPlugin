<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Order;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Order\ExtOrderIdGenerator;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentRequest;
use function strlen;

final class ExtOrderIdGeneratorTest extends TestCase
{
    #[TestWith(['000000001', 42, '000000001-42-d1bfaf4a'])]
    #[TestWith(['#2024/ABC-77', 7, '2024ABC77-7-d1bfaf4a'])]
    #[TestWith(['VERY-LONG-ORDER-NUMBER-12345', 999999999, 'UMBER12345-999999999-d1bfaf4a'])]
    #[TestWith(['', 1, 'order-1-d1bfaf4a'])]
    #[TestWith(['ąę', 1, 'order-1-d1bfaf4a'])]
    public function test_it_builds_a_unique_autopay_order_id(string $number, int $paymentId, string $expected): void
    {
        $generator = new ExtOrderIdGenerator();

        $orderId = $generator->generate(self::payment($paymentId, $number), self::paymentRequest('3F2504E0-4F89-11D3-9A0C-0305E82C3301'));

        self::assertSame($expected, $orderId);
        self::assertLessThanOrEqual(32, strlen($orderId));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,32}$/', $orderId);
        self::assertSame($paymentId, $generator->extractPaymentId($orderId));
    }

    public function test_payment_requests_created_at_the_same_time_get_different_order_ids(): void
    {
        $generator = new ExtOrderIdGenerator();
        $payment = self::payment(1, '000000001');

        // UUID v7: the first 48 bits are the same millisecond timestamp.
        self::assertNotSame(
            $generator->generate($payment, self::paymentRequest('019a5b1e-7c1a-7000-8000-000000000001')),
            $generator->generate($payment, self::paymentRequest('019a5b1e-7c1a-7000-8000-000000000002')),
        );
    }

    #[TestWith(['000000001-42'])]
    #[TestWith(['000000001-42-3F2504E0'])]
    #[TestWith(['000000001-x-d1bfaf4a'])]
    #[TestWith(['00000000001-42-d1bfaf4a'])]
    #[TestWith(['a-42-3f2504e0x'])]
    #[TestWith(['FOREIGN'])]
    public function test_foreign_order_ids_have_no_payment_id(string $orderId): void
    {
        self::assertNull((new ExtOrderIdGenerator())->extractPaymentId($orderId));
    }

    public function test_unsaved_payment_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ExtOrderIdGenerator())->generate(new Payment(), self::paymentRequest('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
    }

    private static function payment(int $id, string $number): Payment
    {
        $payment = new class($id) extends Payment {
            public function __construct(private readonly int $fixedId)
            {
                parent::__construct();
            }

            public function getId(): int
            {
                return $this->fixedId;
            }
        };
        $order = new Order();
        $order->setNumber($number);
        $order->addPayment($payment);

        return $payment;
    }

    private static function paymentRequest(string $hash): PaymentRequest
    {
        return new class($hash) extends PaymentRequest {
            public function __construct(private readonly string $fixedHash)
            {
                parent::__construct(new Payment(), new PaymentMethod());
            }

            public function getId(): string
            {
                return $this->fixedHash;
            }
        };
    }
}
