<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Config;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://testpay.autopay.eu',
            self::Production => 'https://pay.autopay.eu',
        };
    }
}
