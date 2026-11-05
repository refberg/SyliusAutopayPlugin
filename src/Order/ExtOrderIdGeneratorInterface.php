<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Order;

use InvalidArgumentException;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Builds the Autopay "OrderID" of a payment attempt (1-32 characters: letters, digits, "-" and "_").
 *
 * extractPaymentId() must read the payment ID back from any value returned by generate(): ITNs are matched to payments by it.
 */
interface ExtOrderIdGeneratorInterface
{
    /**
     * @throws InvalidArgumentException
     */
    public function generate(PaymentInterface $payment, PaymentRequestInterface $paymentRequest): string;

    public function extractPaymentId(string $extOrderId): ?int;
}
