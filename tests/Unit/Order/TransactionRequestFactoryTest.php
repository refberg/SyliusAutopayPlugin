<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Order;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Order\TransactionRequestFactory;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use function array_keys;
use function mb_strlen;
use function str_repeat;

final class TransactionRequestFactoryTest extends TestCase
{
    public function test_it_builds_the_parameters_in_hash_order_from_the_sylius_payment(): void
    {
        [$payment, $request] = self::payment(1550, 'buyer@example.com');

        $parameters = (new TransactionRequestFactory(self::afterPayUrl()))->create($request, $payment, 'ORDER-1');

        self::assertSame([
            'OrderID' => 'ORDER-1',
            'Amount' => '15.50',
            'Description' => 'Order 000000001',
            'Currency' => 'PLN',
            'CustomerEmail' => 'buyer@example.com',
            'ReturnURL' => 'https://shop.example/after-pay',
        ], $parameters);
    }

    public function test_invalid_email_is_not_sent(): void
    {
        [$payment, $request] = self::payment(100, 'not-an-email');

        self::assertArrayNotHasKey('CustomerEmail', (new TransactionRequestFactory(self::afterPayUrl()))->create($request, $payment, 'ORDER-1'));
    }

    public function test_validity_time_is_sent_in_warsaw_time_when_configured(): void
    {
        [$payment, $request] = self::payment(100, 'buyer@example.com');

        $parameters = (new TransactionRequestFactory(self::afterPayUrl(), 3600))->create($request, $payment, 'ORDER-1');

        self::assertSame(['OrderID', 'Amount', 'Description', 'Currency', 'CustomerEmail', 'ValidityTime', 'ReturnURL'], array_keys($parameters));
        $validity = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $parameters['ValidityTime'], new DateTimeZone('Europe/Warsaw'));
        self::assertInstanceOf(DateTimeImmutable::class, $validity);
        self::assertEqualsWithDelta((new DateTimeImmutable('+3600 seconds'))->getTimestamp(), $validity->getTimestamp(), 5);
    }

    public function test_description_is_truncated(): void
    {
        [$payment, $request] = self::payment(100, 'buyer@example.com', str_repeat('9', 100));

        self::assertSame(79, mb_strlen((new TransactionRequestFactory(self::afterPayUrl()))->create($request, $payment, 'ORDER-1')['Description']));
    }

    public function test_return_url_from_the_payload_wins(): void
    {
        [$payment, $request] = self::payment(100, 'buyer@example.com');
        $request->setPayload(['return_url' => 'https://app.example/return?x=1']);

        self::assertSame('https://app.example/return?x=1', (new TransactionRequestFactory(null))->create($request, $payment, 'ORDER-1')['ReturnURL']);
    }

    public function test_return_url_must_point_to_the_channel_or_an_allowed_host(): void
    {
        [$payment, $request] = self::payment(100, 'buyer@example.com');
        $channel = new Channel();
        $channel->setHostname('shop.example');
        $payment->getOrder()?->setChannel($channel);
        $factory = new TransactionRequestFactory(null, 0, ['App.Example']);

        $request->setPayload(['return_url' => 'https://shop.example/return']);
        self::assertSame('https://shop.example/return', $factory->create($request, $payment, 'ORDER-1')['ReturnURL']);
        $request->setPayload(['return_url' => 'https://app.example/return']);
        self::assertSame('https://app.example/return', $factory->create($request, $payment, 'ORDER-1')['ReturnURL']);

        $request->setPayload(['return_url' => 'https://evil.example/return']);
        $this->expectException(InvalidArgumentException::class);
        $factory->create($request, $payment, 'ORDER-1');
    }

    #[TestWith(['javascript:alert(1)'])]
    #[TestWith(['ftp://example.com/x'])]
    #[TestWith(['/relative'])]
    #[TestWith([12])]
    public function test_invalid_return_url_is_rejected(mixed $url): void
    {
        [$payment, $request] = self::payment(100, 'buyer@example.com');
        $request->setPayload(['return_url' => $url]);

        $this->expectException(InvalidArgumentException::class);

        (new TransactionRequestFactory(self::afterPayUrl()))->create($request, $payment, 'ORDER-1');
    }

    public function test_return_url_is_required_without_the_shop(): void
    {
        [$payment, $request] = self::payment(100, 'buyer@example.com');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('return_url');

        (new TransactionRequestFactory(null))->create($request, $payment, 'ORDER-1');
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    public function test_non_positive_amount_is_rejected(int $amount): void
    {
        [$payment, $request] = self::payment($amount, 'buyer@example.com');

        $this->expectException(InvalidArgumentException::class);

        (new TransactionRequestFactory(self::afterPayUrl()))->create($request, $payment, 'ORDER-1');
    }

    public function test_payment_without_order_is_rejected(): void
    {
        $payment = new Payment();
        $payment->setAmount(100);
        $payment->setCurrencyCode('PLN');

        $this->expectException(InvalidArgumentException::class);

        (new TransactionRequestFactory(self::afterPayUrl()))->create(new PaymentRequest($payment, new PaymentMethod()), $payment, 'ORDER-1');
    }

    /**
     * @return array{Payment, PaymentRequest}
     */
    private static function payment(int $amount, string $email, string $number = '000000001'): array
    {
        $customer = new Customer();
        $customer->setEmail($email);
        $order = new Order();
        $order->setNumber($number);
        $order->setCustomer($customer);

        $payment = new Payment();
        $payment->setAmount($amount);
        $payment->setCurrencyCode('PLN');
        $order->addPayment($payment);

        return [$payment, new PaymentRequest($payment, new PaymentMethod())];
    }

    private static function afterPayUrl(): UrlProviderInterface
    {
        return new class() implements UrlProviderInterface {
            public function getUrl(PaymentRequestInterface $paymentRequest, int $referenceType = 1): string
            {
                return 'https://shop.example/after-pay';
            }
        };
    }
}
