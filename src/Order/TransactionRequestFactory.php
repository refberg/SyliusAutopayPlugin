<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Order;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Refberg\SyliusAutopayPlugin\Api\Amount;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use function array_filter;
use function filter_var;
use function in_array;
use function is_array;
use function is_string;
use function mb_substr;
use function parse_url;
use function sprintf;
use const FILTER_VALIDATE_EMAIL;
use const FILTER_VALIDATE_URL;
use const PHP_URL_SCHEME;

/**
 * Builds the parameters of an Autopay transaction from a Sylius payment.
 *
 * Amount and currency always come from the Sylius payment, never from the client.
 * The ITN address is not sent: Autopay sends ITNs to the address set for the service ID.
 */
final readonly class TransactionRequestFactory implements TransactionRequestFactoryInterface
{
    public const PAYLOAD_RETURN_URL = 'return_url';

    private const DESCRIPTION_LENGTH = 79;

    public function __construct(
        private ?UrlProviderInterface $afterPayUrlProvider,
        private int $validitySeconds = 0,
    ) {
    }

    public function create(PaymentRequestInterface $paymentRequest, PaymentInterface $payment, string $extOrderId): array
    {
        $order = $payment->getOrder();
        $amount = $payment->getAmount();
        $currency = $payment->getCurrencyCode();

        if (!$order instanceof OrderInterface) {
            throw new InvalidArgumentException('Payment has no order.');
        }
        if (null === $amount || $amount <= 0 || null === $currency || '' === $currency) {
            throw new InvalidArgumentException('Payment amount and currency are required.');
        }

        $email = $order->getCustomer()?->getEmail();

        // Field order matters: it is the order of the values in the hash.
        return array_filter([
            'OrderID' => $extOrderId,
            'Amount' => Amount::format($amount),
            'Description' => mb_substr(sprintf('Order %s', (string) $order->getNumber()), 0, self::DESCRIPTION_LENGTH),
            'Currency' => $currency,
            'CustomerEmail' => null !== $email && false !== filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            'ValidityTime' => $this->validityTime(),
            'ReturnURL' => $this->returnUrl($paymentRequest),
        ], static fn (string $value): bool => '' !== $value);
    }

    private function validityTime(): string
    {
        if ($this->validitySeconds <= 0) {
            return '';
        }

        return (new DateTimeImmutable('+' . $this->validitySeconds . ' seconds'))
            ->setTimezone(new DateTimeZone('Europe/Warsaw'))
            ->format('Y-m-d H:i:s');
    }

    private function returnUrl(PaymentRequestInterface $paymentRequest): string
    {
        $payload = $paymentRequest->getPayload();
        $requested = is_array($payload) ? ($payload[self::PAYLOAD_RETURN_URL] ?? null) : null;

        if (null !== $requested) {
            if (!is_string($requested) || !self::isAbsoluteHttpUrl($requested)) {
                throw new InvalidArgumentException(sprintf('Payload "%s" must be an absolute http(s) URL.', self::PAYLOAD_RETURN_URL));
            }

            return $requested;
        }

        if (!$this->afterPayUrlProvider instanceof UrlProviderInterface) {
            throw new InvalidArgumentException(sprintf('Payload "%s" is required when the shop is not installed.', self::PAYLOAD_RETURN_URL));
        }

        return $this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private static function isAbsoluteHttpUrl(string $url): bool
    {
        return false !== filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
