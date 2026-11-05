<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\Api\Exception;

use function in_array;

/**
 * Autopay answered, but with an error (HTTP 4xx/5xx, an <error> document or an invalid response).
 */
final class AutopayApiException extends AutopayException
{
    /**
     * Error names meaning that Autopay rejected the request signature or the service, i.e. the shared key,
     * hash algorithm or service ID in the plugin configuration (assumption A1 in docs/autopay.md).
     */
    public const AUTHORIZATION_ERRORS = ['INVALID_HASH', 'WRONG_HASH', 'INVALID_SERVICE_ID'];

    public function __construct(
        string $message,
        private readonly int $httpStatusCode,
        private readonly ?string $errorName = null,
    ) {
        parent::__construct($message);
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getErrorName(): ?string
    {
        return $this->errorName;
    }

    public function isTransient(): bool
    {
        return $this->httpStatusCode >= 500 || in_array($this->httpStatusCode, [408, 429], true);
    }

    /**
     * Autopay rejected the plugin credentials; fixed by the merchant, so notifications should be retried.
     */
    public function isAuthorizationError(): bool
    {
        if (null !== $this->errorName) {
            return in_array($this->errorName, self::AUTHORIZATION_ERRORS, true);
        }

        return in_array($this->httpStatusCode, [401, 403], true);
    }
}
