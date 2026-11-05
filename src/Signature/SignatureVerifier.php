<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Signature;

use Refberg\SyliusAutopayPlugin\Config\AutopayCredentials;
use function array_filter;
use function array_values;
use function hash;
use function hash_equals;
use function implode;
use function strtolower;
use function trim;

final readonly class SignatureVerifier implements SignatureVerifierInterface
{
    private const SEPARATOR = '|';

    public function sign(array $values, AutopayCredentials $credentials): string
    {
        $parts = array_values(array_filter($values, static fn (string $value): bool => '' !== $value));
        $parts[] = $credentials->sharedKey;

        return hash($credentials->hashAlgorithm->value, implode(self::SEPARATOR, $parts));
    }

    public function verify(array $values, ?string $hash, AutopayCredentials $credentials): bool
    {
        if (null === $hash || '' === trim($hash) || '' === $credentials->sharedKey) {
            return false;
        }

        return hash_equals($this->sign($values, $credentials), strtolower(trim($hash)));
    }
}
