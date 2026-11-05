<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Support;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client used by the plugin in tests.
 *
 * By default every request goes to FakeAutopay. Only with AUTOPAY_SANDBOX=1 (manual sandbox tests)
 * a real client is used, restricted to the Autopay sandbox host: production is never reachable from tests.
 */
final class TestHttpClientFactory
{
    public static function create(FakeAutopay $fakeAutopay): HttpClientInterface
    {
        if (!SandboxEnvironment::isEnabled()) {
            return new MockHttpClient($fakeAutopay, 'https://' . FakeAutopay::HOST);
        }

        return new SandboxOnlyHttpClient(HttpClient::create());
    }
}
