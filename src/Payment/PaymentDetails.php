<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Payment;

use Refberg\SyliusAutopayPlugin\Api\Model\AutopayTransaction;
use Refberg\SyliusAutopayPlugin\Api\Model\RefundCreated;
use Refberg\SyliusAutopayPlugin\AutopayGateway;
use Sylius\Component\Payment\Model\PaymentInterface;
use function array_reverse;
use function is_array;
use function is_int;
use function is_string;

/**
 * Typed access to the Autopay data stored in Payment::$details[AutopayGateway::DETAILS_KEY].
 *
 * Shape:
 *   orders:             map OrderID => {service_id, remote_id, status, amount, currency}
 *   current_order_id:   OrderID started most recently for this payment
 *   completed_order_id: OrderID that completed this payment
 *   refunds:            map MessageID => {status, amount, currency, remote_id, remote_out_id?};
 *                       refunds made in the Autopay panel: "autopay-<remoteOutID>" => {..., external: true}
 *   warnings:           list of {type, order_id}
 *
 * Only scalars and arrays are stored, so the data stays JSON-serializable.
 */
final class PaymentDetails
{
    public const WARNING_DUPLICATE_PAYMENT = 'duplicate_payment';

    public const WARNING_LATE_PAYMENT = 'payment_completed_after_failure';

    public const WARNING_PAYMENT_METHOD_CHANGED = 'paid_with_previous_payment_method';

    /** The transaction belongs to another Autopay service, so the plugin could not cancel it. */
    public const WARNING_NOT_CANCELLED = 'transaction_not_cancelled_at_autopay';

    /** @var array<array-key, mixed> */
    private array $data;

    private function __construct(private readonly PaymentInterface $payment)
    {
        $data = $payment->getDetails()[AutopayGateway::DETAILS_KEY] ?? [];
        $this->data = is_array($data) ? $data : [];
    }

    public static function of(PaymentInterface $payment): self
    {
        return new self($payment);
    }

    public function save(): void
    {
        $details = $this->payment->getDetails();
        $details[AutopayGateway::DETAILS_KEY] = $this->data;
        $this->payment->setDetails($details);
    }

    public function addOrder(string $orderId, string $serviceId, string $remoteId, int $amount, string $currency): void
    {
        $orders = $this->orders();
        $orders[$orderId] = [
            'service_id' => $serviceId,
            'remote_id' => $remoteId,
            'status' => AutopayTransaction::STATUS_PENDING,
            'amount' => $amount,
            'currency' => $currency,
        ];
        $this->data['orders'] = $orders;
        $this->data['current_order_id'] = $orderId;
    }

    public function hasOrder(string $orderId): bool
    {
        return isset($this->orders()[$orderId]);
    }

    /**
     * The OrderID was started for this payment by the given Autopay service.
     */
    public function hasOrderOfService(string $orderId, string $serviceId): bool
    {
        return $this->hasOrder($orderId) && ($this->orders()[$orderId]['service_id'] ?? null) === $serviceId;
    }

    public function getRemoteId(string $orderId): ?string
    {
        $value = $this->orders()[$orderId]['remote_id'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function getOrderStatus(string $orderId): ?string
    {
        $value = $this->orders()[$orderId]['status'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Records the latest known state. A final status (SUCCESS, FAILURE) is never overwritten.
     * The remoteID is the attempt that decided the status; for SUCCESS it is the one refunds are made for.
     */
    public function recordOrderStatus(string $orderId, string $status, ?string $remoteId = null): void
    {
        $orders = $this->orders();
        if (!isset($orders[$orderId])) {
            return;
        }

        $current = $orders[$orderId]['status'] ?? null;
        if (AutopayTransaction::isFinalStatus(is_string($current) ? $current : null)) {
            return;
        }

        $orders[$orderId]['status'] = $status;
        if (null !== $remoteId) {
            $orders[$orderId]['remote_id'] = $remoteId;
        }
        $this->data['orders'] = $orders;
    }

    public function getCurrentOrderId(): ?string
    {
        $value = $this->data['current_order_id'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function getCompletedOrderId(): ?string
    {
        $value = $this->data['completed_order_id'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function setCompletedOrderId(string $orderId): void
    {
        $this->data['completed_order_id'] = $orderId;
    }

    /**
     * @return list<string> OrderIDs whose last known status is not final, newest first
     */
    public function getOpenOrderIds(): array
    {
        $open = [];
        foreach ($this->orders() as $orderId => $order) {
            $status = $order['status'] ?? null;
            if (!AutopayTransaction::isFinalStatus(is_string($status) ? $status : null)) {
                $open[] = (string) $orderId;
            }
        }

        return array_reverse($open);
    }

    public function hasOpenOrders(): bool
    {
        return [] !== $this->getOpenOrderIds();
    }

    public function addWarning(string $type, string $orderId): void
    {
        $warnings = $this->warnings();
        foreach ($warnings as $warning) {
            if (($warning['type'] ?? null) === $type && ($warning['order_id'] ?? null) === $orderId) {
                return;
            }
        }

        $warnings[] = ['type' => $type, 'order_id' => $orderId];
        $this->data['warnings'] = $warnings;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function warnings(): array
    {
        $warnings = $this->data['warnings'] ?? [];
        if (!is_array($warnings)) {
            return [];
        }

        $result = [];
        foreach ($warnings as $warning) {
            if (is_array($warning)) {
                $result[] = $warning;
            }
        }

        return $result;
    }

    public function recordRefund(string $messageId, string $remoteId, string $status, int $amount, string $currency): void
    {
        $refunds = $this->refunds();
        $refunds[$messageId] = [
            'remote_id' => $remoteId,
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
        ];
        $this->data['refunds'] = $refunds;
    }

    /**
     * A final status (DONE, CANCELED) is never overwritten.
     *
     * @return bool true when the stored status changed
     */
    public function updateRefundStatus(string $messageId, string $status): bool
    {
        $refunds = $this->refunds();
        $current = $refunds[$messageId]['status'] ?? null;
        if (!isset($refunds[$messageId]) || $current === $status || RefundCreated::isFinalStatus(is_string($current) ? $current : null)) {
            return false;
        }

        $refunds[$messageId]['status'] = $status;
        $this->data['refunds'] = $refunds;

        return true;
    }

    /**
     * @return list<string> MessageIDs of refunds requested by Sylius whose final status is not known yet
     */
    public function getPendingRefundIds(): array
    {
        $pending = [];
        foreach ($this->refunds() as $messageId => $refund) {
            $status = $refund['status'] ?? null;
            if (true !== ($refund['external'] ?? false) && !RefundCreated::isFinalStatus(is_string($status) ? $status : null)) {
                $pending[] = (string) $messageId;
            }
        }

        return $pending;
    }

    public function finalizedRefundAmount(): int
    {
        return $this->refundAmount(static fn (mixed $status): bool => RefundCreated::STATUS_DONE === $status);
    }

    /**
     * Refunds that are not canceled: done or still in progress.
     */
    public function activeRefundAmount(): int
    {
        return $this->refundAmount(static fn (mixed $status): bool => RefundCreated::STATUS_CANCELED !== $status);
    }

    public function hasRefund(string $key): bool
    {
        return isset($this->refunds()[$key]);
    }

    public function setRefundRemoteOutId(string $key, string $remoteOutId): void
    {
        $refunds = $this->refunds();
        if (isset($refunds[$key])) {
            $refunds[$key]['remote_out_id'] = $remoteOutId;
            $this->data['refunds'] = $refunds;
        }
    }

    /**
     * Records a refund made outside Sylius (in the Autopay panel), reported by an ISTN.
     *
     * @return string key of the refund
     */
    public function recordExternalRefund(string $remoteOutId, string $status, int $amount, string $currency): string
    {
        $key = 'autopay-' . $remoteOutId;
        $refunds = $this->refunds();
        if (!isset($refunds[$key])) {
            $refunds[$key] = [
                'remote_out_id' => $remoteOutId,
                'status' => $status,
                'amount' => $amount,
                'currency' => $currency,
                'external' => true,
            ];
            $this->data['refunds'] = $refunds;
        }

        return $key;
    }

    /**
     * Only refunds requested by Sylius count; refunds made in the Autopay panel are covered by activeRefundAmount().
     */
    public function hasActiveRefund(): bool
    {
        foreach ($this->refunds() as $refund) {
            if (true !== ($refund['external'] ?? false) && ($refund['status'] ?? null) !== RefundCreated::STATUS_CANCELED) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<array-key, array<array-key, mixed>>
     */
    public function refunds(): array
    {
        $refunds = $this->data['refunds'] ?? [];

        return is_array($refunds) ? self::mapOfArrays($refunds) : [];
    }

    /**
     * @return array<array-key, array<array-key, mixed>>
     */
    private function orders(): array
    {
        $orders = $this->data['orders'] ?? [];

        return is_array($orders) ? self::mapOfArrays($orders) : [];
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return array<array-key, array<array-key, mixed>>
     */
    private static function mapOfArrays(array $items): array
    {
        $result = [];
        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @param callable(mixed): bool $statusFilter
     */
    private function refundAmount(callable $statusFilter): int
    {
        $total = 0;
        foreach ($this->refunds() as $refund) {
            $amount = $refund['amount'] ?? 0;
            if (is_int($amount) && $statusFilter($refund['status'] ?? null)) {
                $total += $amount;
            }
        }

        return $total;
    }
}
