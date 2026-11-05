<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Order;

use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use Sylius\Component\Payment\Model\PaymentInterface;

interface AutopayTransactionValidatorInterface
{
    /**
     * @return list<string> problems found, empty when the transaction matches the payment
     */
    public function validate(AutopayTransaction $transaction, PaymentInterface $payment, AutopayCredentials $credentials): array;
}
