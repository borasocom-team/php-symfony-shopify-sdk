<?php
namespace TurboLabIt\ShopifySdk\tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel;
use TurboLabIt\ShopifySdk\ShopifySdkBundle;


abstract class Base extends TestCase
{
    protected string $serviceId = '';

    protected function getInstance()
    {
        $kernel = new ShopifySdkTestingKernel("test", true);
        $kernel->boot();
        $container = $kernel->getContainer();

        $connector = $container->get($this->serviceId);
        return $connector;
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
