<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Notification;

use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;

/**
 * parse() and parseStoredPayload() return null for anything that is not a correctly signed, well-formed ITN
 * of the configured service.
 */
interface NotificationParserInterface
{
    public function parse(string $rawBody, AutopayCredentials $credentials): ?Notification;

    public function parseStoredPayload(mixed $payload, AutopayCredentials $credentials): ?Notification;
}
