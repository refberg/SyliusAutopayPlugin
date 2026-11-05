<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Support;

use LogicException;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use function is_string;
use function parse_url;
use function sprintf;
use const PHP_URL_HOST;

/**
 * Real HTTP client for manual sandbox tests that refuses every host except the Autopay sandbox.
 */
final class SandboxOnlyHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    /**
     * @param array<mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (FakeAutopay::HOST !== $host) {
            throw new LogicException(sprintf('Sandbox tests must not call "%s".', is_string($host) ? $host : $url));
        }

        return $this->client->request($method, $url, $options);
    }
}
