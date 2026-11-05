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
use function array_map;
use function filter_var;
use function in_array;
use function is_array;
use function is_string;
use function mb_substr;
use function parse_url;
use function preg_replace;
use function sprintf;
use function strtolower;
use const FILTER_VALIDATE_EMAIL;
use const FILTER_VALIDATE_URL;
use const PHP_URL_HOST;
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

    /**
     * @param list<string> $allowedReturnHosts hosts a "return_url" payload may point to, besides the order channel's hostname
     */
    public function __construct(
        private ?UrlProviderInterface $afterPayUrlProvider,
        private int $validitySeconds = 0,
        private array $allowedReturnHosts = [],
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
            // Autopay accepts only Latin letters, digits and ". : - ," in the description.
            'Description' => mb_substr(sprintf('Order %s', preg_replace('/[^A-Za-z0-9.:\-, ]/', '', (string) $order->getNumber()) ?? ''), 0, self::DESCRIPTION_LENGTH),
            'Currency' => $currency,
            'CustomerEmail' => null !== $email && false !== filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            'ValidityTime' => $this->validityTime(),
            'ReturnURL' => $this->returnUrl($paymentRequest, $order),
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

    private function returnUrl(PaymentRequestInterface $paymentRequest, OrderInterface $order): string
    {
        $payload = $paymentRequest->getPayload();
        $requested = is_array($payload) ? ($payload[self::PAYLOAD_RETURN_URL] ?? null) : null;

        if (null !== $requested) {
            if (!is_string($requested) || !self::isAbsoluteHttpUrl($requested)) {
                throw new InvalidArgumentException(sprintf('Payload "%s" must be an absolute http(s) URL.', self::PAYLOAD_RETURN_URL));
            }
            if (!$this->isAllowedHost($requested, $order)) {
                throw new InvalidArgumentException(sprintf('Payload "%s" must point to the shop (channel hostname or refberg_autopay.allowed_return_hosts).', self::PAYLOAD_RETURN_URL));
            }

            return $requested;
        }

        if (!$this->afterPayUrlProvider instanceof UrlProviderInterface) {
            throw new InvalidArgumentException(sprintf('Payload "%s" is required when the shop is not installed.', self::PAYLOAD_RETURN_URL));
        }

        return $this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Keeps the Autopay page from redirecting customers to any site (open redirect). Without a channel hostname
     * and without configured hosts there is nothing to compare with, so any host is accepted.
     */
    private function isAllowedHost(string $url, OrderInterface $order): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowed = array_map(strtolower(...), $this->allowedReturnHosts);
        $channelHost = $order->getChannel()?->getHostname();
        if (null !== $channelHost && '' !== $channelHost) {
            $allowed[] = strtolower($channelHost);
        }

        return [] === $allowed || in_array($host, $allowed, true);
    }

    private static function isAbsoluteHttpUrl(string $url): bool
    {
        return false !== filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
