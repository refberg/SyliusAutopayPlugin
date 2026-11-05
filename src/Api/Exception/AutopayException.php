<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Api\Exception;

use RuntimeException;

/**
 * Base exception for Autopay communication problems.
 *
 * Messages never contain the shared key, hashes or raw request bodies,
 * so they are safe to log and to store in PaymentRequest::$responseData.
 */
class AutopayException extends RuntimeException
{
}
