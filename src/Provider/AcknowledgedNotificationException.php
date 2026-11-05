<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Provider;

use RuntimeException;

/**
 * Thrown by the notify payment provider for a valid notification that belongs to no payment
 * and needs no processing (an ISTN about a settlement): Sylius would store it otherwise.
 * AcknowledgedNotificationListener answers it with the confirmation, so Autopay stops repeating it.
 *
 * @internal
 */
final class AcknowledgedNotificationException extends RuntimeException
{
    public function __construct(public readonly string $confirmation)
    {
        parent::__construct('Autopay notification acknowledged without processing.');
    }
}
