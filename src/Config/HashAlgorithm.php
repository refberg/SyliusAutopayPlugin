<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Config;

/**
 * Hash function of the Autopay service, as set by Autopay for the service ID.
 * MD5 and SHA-1, which Autopay also offers, are not supported.
 */
enum HashAlgorithm: string
{
    case Sha256 = 'sha256';
    case Sha512 = 'sha512';
}
