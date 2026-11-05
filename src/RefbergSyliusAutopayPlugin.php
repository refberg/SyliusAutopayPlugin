<?php

declare(strict_types=1);

namespace Refberg\SyliusAutopayPlugin;

use Refberg\SyliusAutopayPlugin\DependencyInjection\RefbergSyliusAutopayExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use function dirname;

final class RefbergSyliusAutopayPlugin extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new RefbergSyliusAutopayExtension();
        }

        return $this->extension;
    }

    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
