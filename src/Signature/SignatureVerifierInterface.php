<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Signature;

use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;

/**
 * Autopay "Hash": hash(algorithm, value1|value2|...|sharedKey), with empty values skipped.
 */
interface SignatureVerifierInterface
{
    /**
     * @param list<string> $values field values in the order defined by Autopay
     */
    public function sign(array $values, AutopayCredentials $credentials): string;

    /**
     * @param list<string> $values field values in the order defined by Autopay
     */
    public function verify(array $values, ?string $hash, AutopayCredentials $credentials): bool;
}
