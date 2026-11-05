<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Payment;

use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayException;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Sylius\Component\Payment\Model\PaymentInterface;

interface RefundSynchronizerInterface
{
    /**
     * Reads the status of every refund of the payment that is still in progress.
     *
     * @throws AutopayException
     *
     * @return int number of refunds whose status changed
     */
    public function synchronize(PaymentInterface $payment, AutopayCredentials $credentials): int;
}
