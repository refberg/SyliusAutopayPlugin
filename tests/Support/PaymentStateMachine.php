<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Support;

use LogicException;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function sprintf;

/**
 * Minimal copy of the Sylius "sylius_payment" and "sylius_payment_request" graphs for unit tests.
 */
final class PaymentStateMachine implements StateMachineInterface
{
    private const GRAPHS = [
        PaymentTransitions::GRAPH => [
            PaymentTransitions::TRANSITION_PROCESS => [['new'], 'processing'],
            PaymentTransitions::TRANSITION_AUTHORIZE => [['new', 'processing'], 'authorized'],
            PaymentTransitions::TRANSITION_COMPLETE => [['new', 'processing', 'authorized'], 'completed'],
            PaymentTransitions::TRANSITION_FAIL => [['new', 'processing', 'authorized'], 'failed'],
            PaymentTransitions::TRANSITION_CANCEL => [['new', 'processing', 'authorized'], 'cancelled'],
            PaymentTransitions::TRANSITION_REFUND => [['completed'], 'refunded'],
        ],
        PaymentRequestTransitions::GRAPH => [
            PaymentRequestTransitions::TRANSITION_PROCESS => [['new'], 'processing'],
            PaymentRequestTransitions::TRANSITION_COMPLETE => [['new', 'processing'], 'completed'],
            PaymentRequestTransitions::TRANSITION_FAIL => [['new', 'processing'], 'failed'],
            PaymentRequestTransitions::TRANSITION_CANCEL => [['new', 'processing'], 'cancelled'],
        ],
    ];

    /** @var list<array{string, string}> */
    public array $applied = [];

    public function can(object $subject, string $graphName, string $transition): bool
    {
        $definition = self::GRAPHS[$graphName][$transition] ?? null;

        return null !== $definition && in_array($this->state($subject), $definition[0], true);
    }

    public function apply(object $subject, string $graphName, string $transition, array $context = []): void
    {
        if (!$this->can($subject, $graphName, $transition)) {
            throw new LogicException(sprintf('Transition "%s" cannot be applied from "%s".', $transition, (string) $this->state($subject)));
        }

        $to = self::GRAPHS[$graphName][$transition][1];
        if ($subject instanceof PaymentInterface || $subject instanceof PaymentRequestInterface) {
            $subject->setState($to);
        }

        $this->applied[] = [$graphName, $transition];
    }

    public function getEnabledTransitions(object $subject, string $graphName): array
    {
        return [];
    }

    public function getTransitionFromState(object $subject, string $graphName, string $fromState): ?string
    {
        return null;
    }

    public function getTransitionToState(object $subject, string $graphName, string $toState): ?string
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function appliedTransitions(string $graphName): array
    {
        return array_values(array_map(
            static fn (array $item): string => $item[1],
            array_filter($this->applied, static fn (array $item): bool => $item[0] === $graphName),
        ));
    }

    private function state(object $subject): ?string
    {
        return $subject instanceof PaymentInterface || $subject instanceof PaymentRequestInterface ? $subject->getState() : null;
    }
}
