<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\Api;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\Api\Json;

final class JsonTest extends TestCase
{
    public function test_it_reads_non_empty_strings_and_integers(): void
    {
        $data = ['a' => ' x ', 'b' => 12, 'c' => '', 'd' => ['x'], 'e' => 1.5, 'f' => '  '];

        self::assertSame('x', Json::string($data, 'a'));
        self::assertSame('12', Json::string($data, 'b'));
        self::assertNull(Json::string($data, 'c'));
        self::assertNull(Json::string($data, 'd'));
        self::assertNull(Json::string($data, 'e'));
        self::assertNull(Json::string($data, 'f'));
        self::assertNull(Json::string($data, 'missing'));
    }
}
