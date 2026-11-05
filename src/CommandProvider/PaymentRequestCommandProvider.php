<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\CommandProvider;

use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * Maps a payment request to one of the plugin commands; one service per action.
 *
 * @internal
 */
final readonly class PaymentRequestCommandProvider implements PaymentRequestCommandProviderInterface
{
    /**
     * @param class-string<PaymentRequestHashAwareInterface> $commandClass
     */
    public function __construct(private string $commandClass)
    {
    }

    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return true;
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        return new ($this->commandClass)($paymentRequest->getId());
    }
}
