<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Refberg\SyliusAutopayPlugin\Support\FakeAutopay;
use Tests\Refberg\SyliusAutopayPlugin\Support\TestHttpClientFactory;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    if (!str_starts_with((string) $container->env(), 'test')) {
        return;
    }

    $services = $container->services();

    // Autopay traffic in tests goes to an in-memory fake of the Autopay sandbox; FakeAutopay rejects any
    // other host. Only AUTOPAY_SANDBOX=1 switches to the real sandbox (see tests/Sandbox).
    $services->set(FakeAutopay::class)->public();

    $services->set('refberg_autopay.http_client', HttpClientInterface::class)
        ->factory([TestHttpClientFactory::class, 'create'])
        ->args([service(FakeAutopay::class)])
        ->public();
};
