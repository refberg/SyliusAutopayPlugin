<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Api;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\Amount;

final class AmountTest extends TestCase
{
    #[TestWith([0, '0.00'])]
    #[TestWith([5, '0.05'])]
    #[TestWith([1500, '15.00'])]
    #[TestWith([123456789, '1234567.89'])]
    public function test_it_formats_minor_units(int $minor, string $formatted): void
    {
        self::assertSame($formatted, Amount::format($minor));
        self::assertSame($minor, Amount::parse($formatted));
    }

    public function test_negative_amount_cannot_be_formatted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Amount::format(-1);
    }

    #[TestWith(['15', 1500])]
    #[TestWith(['15.5', 1550])]
    #[TestWith(['15,50', 1550])]
    #[TestWith(['0.01', 1])]
    public function test_it_parses_autopay_amounts(string $amount, int $minor): void
    {
        self::assertSame($minor, Amount::parse($amount));
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['-1.00'])]
    #[TestWith(['1.005'])]
    #[TestWith(['1e3'])]
    #[TestWith([' 1.00'])]
    #[TestWith(['1234567890123456.00'])]
    public function test_it_rejects_invalid_amounts(?string $amount): void
    {
        self::assertNull(Amount::parse($amount));
    }
}
