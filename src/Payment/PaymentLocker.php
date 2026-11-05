<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Payment;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Payment\Model\PaymentInterface;

/**
 * Takes a row lock on the payment for the rest of the current transaction and reloads it,
 * so concurrent notifications for the same payment are processed one after another.
 *
 * Payment request handlers run inside the "doctrine_transaction" middleware of
 * the payment request command bus, which provides the transaction.
 */
final readonly class PaymentLocker implements PaymentLockerInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function lock(PaymentInterface $payment): void
    {
        if (!$this->entityManager->contains($payment) || !$this->entityManager->getConnection()->isTransactionActive()) {
            return;
        }

        $this->entityManager->lock($payment, LockMode::PESSIMISTIC_WRITE);

        // Reload the latest committed data, unless this request already changed the payment
        // (e.g. the admin "refund" transition that triggered this handler): refreshing would drop that change.
        if (!$this->hasPendingChanges($payment)) {
            $this->entityManager->refresh($payment);
        }
    }

    private function hasPendingChanges(PaymentInterface $payment): bool
    {
        $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($payment);

        return ($original['state'] ?? null) !== $payment->getState() ||
            ($original['details'] ?? []) !== $payment->getDetails();
    }
}
