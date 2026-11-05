<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Refberg\SyliusAutopayPlugin\Order\TransactionRequestFactoryInterface;
use Refberg\SyliusAutopayPlugin\OrderPay\CaptureHttpResponseProvider;
use Refberg\SyliusAutopayPlugin\OrderPay\StatusHttpResponseProvider;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->private();

    $services->get(TransactionRequestFactoryInterface::class)
        ->arg(0, service('sylius_shop.provider.order_pay.after_pay_url'));

    $services->get(CaptureHttpResponseProvider::class)
        ->arg(0, service('sylius_shop.provider.order_pay.after_pay_url'));

    $services->set(StatusHttpResponseProvider::class)
        ->args([service('sylius_shop.provider.order_pay.final_url')])
        ->tag('refberg_autopay.http_response_provider', ['action' => PaymentRequestInterface::ACTION_STATUS]);
};
