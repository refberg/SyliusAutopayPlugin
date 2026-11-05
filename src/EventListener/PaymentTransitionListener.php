<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\EventListener;

use Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Turns a completed "sylius_payment" transition of an Autopay payment into a payment request
 * (e.g. "refund" → ACTION_REFUND, "cancel" → ACTION_CANCEL) and dispatches it.
 *
 * @internal
 */
final readonly class PaymentTransitionListener
{
    /**
     * @param PaymentRequestFactoryInterface<PaymentRequestInterface> $paymentRequestFactory
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     */
    public function __construct(
        private CredentialsResolverInterface $credentialsResolver,
        private PaymentRequestFactoryInterface $paymentRequestFactory,
        private PaymentRequestRepositoryInterface $paymentRequestRepository,
        private PaymentRequestAnnouncerInterface $paymentRequestAnnouncer,
        private string $action,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface) {
            return;
        }

        $method = $payment->getMethod();
        if (!$method instanceof PaymentMethodInterface || !$this->credentialsResolver->supports($method)) {
            return;
        }

        $existing = $this->paymentRequestRepository->findOneByActionPaymentAndMethod($this->action, $payment, $method);
        if ($existing instanceof PaymentRequestInterface) {
            return;
        }

        $paymentRequest = $this->paymentRequestFactory->create($payment, $method);
        $paymentRequest->setAction($this->action);
        $this->paymentRequestRepository->add($paymentRequest);

        $this->paymentRequestAnnouncer->dispatchPaymentRequestCommand($paymentRequest);
    }
}
