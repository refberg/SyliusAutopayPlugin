<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Order;

use InvalidArgumentException;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

interface TransactionRequestFactoryInterface
{
    /**
     * @throws InvalidArgumentException
     *
     * @return array<string, string> transaction parameters without ServiceID and Hash, in the order defined by Autopay
     */
    public function create(PaymentRequestInterface $paymentRequest, PaymentInterface $payment, string $extOrderId): array;
}
