<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Refberg\SyliusAutopayPlugin\DependencyInjection\RefbergSyliusAutopayExtension;
use Refberg\SyliusAutopayPlugin\Order\TransactionRequestFactoryInterface;
use Refberg\SyliusAutopayPlugin\OrderPay\StatusHttpResponseProvider;
use Sylius\Bundle\ShopBundle\SyliusShopBundle;
use Sylius\TwigHooks\DependencyInjection\SyliusTwigHooksExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use function class_exists;
use function interface_exists;
use function str_starts_with;

final class RefbergSyliusAutopayExtensionTest extends TestCase
{
    public function test_shop_services_are_registered_with_the_shop_bundle(): void
    {
        $container = self::load(['SyliusShopBundle' => SyliusShopBundle::class]);

        self::assertTrue($container->hasDefinition(StatusHttpResponseProvider::class));
        $afterPayUrlProvider = $container->getDefinition(TransactionRequestFactoryInterface::class)->getArgument(0);
        self::assertInstanceOf(Reference::class, $afterPayUrlProvider);
        self::assertSame('sylius_shop.provider.order_pay.after_pay_url', (string) $afterPayUrlProvider);
    }

    public function test_shop_services_are_not_registered_without_the_shop_bundle(): void
    {
        $container = self::load([]);

        self::assertFalse($container->hasDefinition(StatusHttpResponseProvider::class));
        self::assertNull($container->getDefinition(TransactionRequestFactoryInterface::class)->getArgument(0));
    }

    public function test_parameters_have_defaults(): void
    {
        $container = self::load([]);

        self::assertEquals(15.0, $container->getParameter('refberg_autopay.http_timeout'));
        self::assertSame(0, $container->getParameter('refberg_autopay.transaction_validity'));
    }

    public function test_injected_plugin_services_are_registered_under_their_interface(): void
    {
        $container = self::load([]);

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();
            if (null === $class || !str_starts_with($class, 'Refberg\\SyliusAutopayPlugin\\') || !class_exists($id) && !interface_exists($id)) {
                continue;
            }

            $interface = $class . 'Interface';
            if (interface_exists($interface)) {
                self::assertSame($interface, $id, $class);
            }
        }
    }

    public function test_admin_twig_hooks_are_prepended_when_twig_hooks_are_enabled(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new SyliusTwigHooksExtension());

        (new RefbergSyliusAutopayExtension())->prepend($container);

        $gatewayConfiguration = [
            'refberg_autopay' => [
                'template' => '@RefbergSyliusAutopayPlugin/admin/payment_method/gateway_configuration.html.twig',
                'priority' => 0,
            ],
        ];
        self::assertSame([['hooks' => [
            'sylius_admin.payment_method.create.content.form.sections.gateway_configuration.refberg_autopay' => $gatewayConfiguration,
            'sylius_admin.payment_method.update.content.form.sections.gateway_configuration.refberg_autopay' => $gatewayConfiguration,
        ]]], $container->getExtensionConfig('sylius_twig_hooks'));
    }

    public function test_no_twig_hooks_are_configured_without_twig_hooks(): void
    {
        $container = new ContainerBuilder();

        (new RefbergSyliusAutopayExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('sylius_twig_hooks'));
    }

    /**
     * @param array<string, class-string> $bundles
     */
    private static function load(array $bundles): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', $bundles);

        (new RefbergSyliusAutopayExtension())->load([], $container);

        return $container;
    }
}
