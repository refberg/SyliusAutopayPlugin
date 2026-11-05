<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Payment;

use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Input must be a transaction that was read from the Autopay API and validated against the payment.
 * Rules must be monotonic, so duplicated or out-of-order notifications cannot change the outcome
 * and the order is never paid twice.
 */
interface PaymentStateApplierInterface
{
    public const RESULT_COMPLETED = 'completed';

    public const RESULT_FAILED = 'failed';

    public const RESULT_UNCHANGED = 'unchanged';

    public const RESULT_WARNING = 'warning';

    /**
     * @return string one of self::RESULT_*
     */
    public function apply(PaymentInterface $payment, AutopayTransaction $transaction): string;
}
