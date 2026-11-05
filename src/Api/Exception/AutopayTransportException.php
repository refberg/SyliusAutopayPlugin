<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Api\Exception;

/**
 * Network-level failure (timeout, DNS, TLS). Always safe to retry.
 */
final class AutopayTransportException extends AutopayException
{
}
