<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Payment;

use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayException;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Sylius\Component\Payment\Model\PaymentInterface;

interface PaymentSynchronizerInterface
{
    public const RESULT_INVALID = 'invalid';

    /**
     * @throws AutopayException
     *
     * @return string one of PaymentStateApplierInterface::RESULT_* or self::RESULT_INVALID
     */
    public function synchronize(PaymentInterface $payment, string $orderId, AutopayCredentials $credentials): string;
}
