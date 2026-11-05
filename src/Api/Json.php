<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Api;

use function is_int;
use function is_string;
use function trim;

/**
 * @internal
 */
final class Json
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
