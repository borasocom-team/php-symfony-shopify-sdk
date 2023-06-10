<?php
namespace TurboLabIt\ShopifySdk\tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel;
use TurboLabIt\ShopifySdk\ShopifySdkBundle;


class ShopifyAdminConnectorTest extends TestCase
{
    public function test()
    {
        $kernel = new ShopifySdkTestingKernel("test", true);
        $kernel->boot();
        $container = $kernel->getContainer();

        $connector = $container->get('shopifysdk.admin_connector');
    }
}


class ShopifySdkTestingKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [
            new ShopifySdkBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader)
    {
    }
}
