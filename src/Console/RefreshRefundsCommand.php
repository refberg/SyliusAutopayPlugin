<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Console;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Refberg\SyliusAutopayPlugin\Api\Exception\AutopayException;
use Refberg\SyliusAutopayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusAutopayPlugin\Config\InvalidConfigurationException;
use Refberg\SyliusAutopayPlugin\Payment\PaymentDetails;
use Refberg\SyliusAutopayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusAutopayPlugin\Payment\RefundSynchronizerInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function is_numeric;
use function sprintf;

/**
 * Reads the status of Autopay refunds still in progress. Autopay does not notify about refunds,
 * so run it periodically (e.g. every hour from cron).
 *
 * @internal
 */
#[AsCommand(name: self::NAME, description: 'Reads the status of Autopay refunds that are still in progress.')]
final class RefreshRefundsCommand extends Command
{
    public const NAME = 'refberg:autopay:refresh-refunds';

    /**
     * @param class-string<PaymentInterface> $paymentClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CredentialsResolverInterface $credentialsResolver,
        private readonly PaymentLockerInterface $paymentLocker,
        private readonly RefundSynchronizerInterface $refundSynchronizer,
        private readonly string $paymentClass,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Only payments refunded in the last N days.', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('days');
        if (!is_numeric($days) || (int) $days < 1) {
            $output->writeln('<error>--days must be a positive number.</error>');

            return Command::INVALID;
        }

        /** @var list<int|string> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('p.id')
            ->from($this->paymentClass, 'p')
            ->andWhere('p.state = :state')
            ->andWhere('p.updatedAt >= :since')
            ->setParameter('state', PaymentInterface::STATE_REFUNDED)
            ->setParameter('since', new DateTimeImmutable(sprintf('-%d days', (int) $days)))
            ->getQuery()
            ->getSingleColumnResult();

        $changed = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $changed += $this->entityManager->wrapInTransaction(fn (): int => $this->refresh($id));
            } catch (AutopayException|InvalidConfigurationException $exception) {
                ++$failed;
                $output->writeln(sprintf('<error>Payment %s: %s</error>', (string) $id, $exception->getMessage()));
            }
            $this->entityManager->clear();
        }

        $output->writeln(sprintf('Autopay refunds updated: %d.', $changed));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    private function refresh(int|string $id): int
    {
        $payment = $this->entityManager->find($this->paymentClass, $id);
        $method = $payment?->getMethod();
        if (
            !$payment instanceof PaymentInterface ||
            !$method instanceof PaymentMethodInterface ||
            !$this->credentialsResolver->supports($method) ||
            [] === PaymentDetails::of($payment)->getPendingRefundIds()
        ) {
            return 0;
        }

        $credentials = $this->credentialsResolver->resolve($method);
        $this->paymentLocker->lock($payment);

        return $this->refundSynchronizer->synchronize($payment, $credentials);
    }
}
