<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusAutopayPlugin\HeadlessApplication;

use Refberg\SyliusAutopayPlugin\RefbergSyliusAutopayPlugin;
use Sylius\Bundle\AdminBundle\SyliusAdminBundle;
use Sylius\Bundle\ShopBundle\SyliusShopBundle;
use Sylius\TwigHooks\SyliusTwigHooksBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\WebpackEncoreBundle\WebpackEncoreBundle;
use function dirname;

final class HeadlessKernel extends Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2) . '/vendor/sylius/test-application';
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__, 2) . '/var/headless/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__, 2) . '/var/headless/log';
    }

    public function registerBundles(): iterable
    {
        /** @var array<class-string<BundleInterface>, array<string, bool>> $bundles */
        $bundles = require $this->getProjectDir() . '/config/bundles.php';
        unset(
            $bundles[SyliusShopBundle::class],
            $bundles[SyliusAdminBundle::class],
            $bundles[SyliusTwigHooksBundle::class],
            $bundles[WebpackEncoreBundle::class],
        );
        $bundles[RefbergSyliusAutopayPlugin::class] = ['all' => true];

        foreach ($bundles as $class => $environments) {
            if ($environments[$this->environment] ?? $environments['all'] ?? false) {
                yield new $class();
            }
        }
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->import(__DIR__ . '/config/*.yaml');
        $container->import(__DIR__ . '/../TestApplication/config/config.yaml');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@SyliusApiBundle/Resources/config/routing.yml')->prefix('%sylius.security.api_route%');
        $routes->import('@SyliusPaymentBundle/Resources/config/routing/integrations/sylius.yaml');
    }
}
