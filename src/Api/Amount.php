<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Api;

use InvalidArgumentException;
use function intdiv;
use function preg_match;
use function sprintf;
use function str_pad;
use function str_replace;
use const STR_PAD_RIGHT;

/**
 * Converts between Sylius amounts (minor units) and Autopay amounts ("15.00").
 *
 * @internal
 */
final class Amount
{
    private function __construct()
    {
    }

    public static function format(int $minorUnits): string
    {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('Amount must not be negative.');
        }

        return sprintf('%d.%02d', intdiv($minorUnits, 100), $minorUnits % 100);
    }

    public static function parse(?string $amount): ?int
    {
        if (null === $amount || 1 !== preg_match('/^(\d{1,15})(?:\.(\d{1,2}))?$/', str_replace(',', '.', $amount), $matches)) {
            return null;
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0', STR_PAD_RIGHT);
    }
}
