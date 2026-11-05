<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Order;

use InvalidArgumentException;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use function hash;
use function is_scalar;
use function preg_match;
use function preg_replace;
use function sprintf;
use function strtolower;
use function substr;

/**
 * "<orderNumber, up to 10 characters>-<paymentId>-<8 hex chars of SHA-256 of the payment request hash>", at most 32 characters.
 *
 * The payment request hash is hashed again because its first characters are a timestamp (UUID v7).
 *
 * Every payment attempt gets its own OrderID, so the Autopay state of one attempt never affects another.
 */
final readonly class ExtOrderIdGenerator implements ExtOrderIdGeneratorInterface
{
    private const PATTERN = '/^[A-Za-z0-9]{1,10}-(\d{1,10})-[0-9a-f]{8}$/';

    public function generate(PaymentInterface $payment, PaymentRequestInterface $paymentRequest): string
    {
        $paymentId = $payment->getId();
        $hash = $paymentRequest->getId();
        if (!is_scalar($paymentId) || '' === (string) $paymentId || null === $hash) {
            throw new InvalidArgumentException('Payment and payment request must be persisted before starting an Autopay transaction.');
        }

        $order = $payment->getOrder();
        $number = $order instanceof OrderInterface ? (string) $order->getNumber() : '';
        $number = substr(preg_replace('/[^A-Za-z0-9]/', '', $number) ?? '', -10);
        if ('' === $number) {
            $number = 'order';
        }

        return sprintf('%s-%d-%s', $number, (int) $paymentId, substr(hash('sha256', strtolower($hash)), 0, 8));
    }

    public function extractPaymentId(string $extOrderId): ?int
    {
        if (1 !== preg_match(self::PATTERN, $extOrderId, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
