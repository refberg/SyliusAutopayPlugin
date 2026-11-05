<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Notification;

use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;

interface ConfirmationFactoryInterface
{
    public const CONFIRMED = 'CONFIRMED';

    public const NOT_CONFIRMED = 'NOTCONFIRMED';

    /**
     * Signed <confirmationList> document Autopay expects in the ITN response.
     */
    public function create(Notification $notification, AutopayCredentials $credentials, bool $confirmed): string;
}
