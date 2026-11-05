<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin\DependencyInjection;

use Refberg\SyliusAutopayPlugin\AutopayGateway;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use function array_key_exists;

final class RefbergSyliusAutopayExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');
        if (array_key_exists('SyliusShopBundle', $bundles)) {
            $loader->load('services/integrations/sylius_shop.php');
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('sylius_twig_hooks')) {
            return;
        }

        $gatewayConfiguration = [
            AutopayGateway::FACTORY_NAME => [
                'template' => '@RefbergSyliusAutopayPlugin/admin/payment_method/gateway_configuration.html.twig',
                'priority' => 0,
            ],
        ];
        // Prepended, so the application configuration can still override the plugin hooks.
        $container->prependExtensionConfig('sylius_twig_hooks', ['hooks' => [
            'sylius_admin.payment_method.create.content.form.sections.gateway_configuration.' . AutopayGateway::FACTORY_NAME => $gatewayConfiguration,
            'sylius_admin.payment_method.update.content.form.sections.gateway_configuration.' . AutopayGateway::FACTORY_NAME => $gatewayConfiguration,
            // Autopay transaction, refunds and warnings next to the payment on the admin order page.
            'sylius_admin.order.show.content.sections.payments.item' => [
                AutopayGateway::FACTORY_NAME => [
                    'template' => '@RefbergSyliusAutopayPlugin/admin/order/payment_details.html.twig',
                    'priority' => 150,
                ],
            ],
        ]]);
    }

    public function getAlias(): string
    {
        return 'refberg_sylius_autopay';
    }
}
