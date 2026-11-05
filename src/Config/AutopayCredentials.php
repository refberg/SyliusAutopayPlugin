<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Config;

use LogicException;
use SensitiveParameter;

final readonly class AutopayCredentials
{
    public function __construct(
        public string $serviceId,
        #[SensitiveParameter]
        public string $sharedKey,
        public HashAlgorithm $hashAlgorithm,
        public Environment $environment,
    ) {
    }

    /**
     * Keeps the shared key out of var_dump(), dump() and debug output.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'serviceId' => $this->serviceId,
            'sharedKey' => '***',
            'hashAlgorithm' => $this->hashAlgorithm->value,
            'environment' => $this->environment->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new LogicException('Autopay credentials must not be serialized.');
    }
}
