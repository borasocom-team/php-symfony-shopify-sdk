<?php
namespace TurboLabIt\ShopifySdk\tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel;
use TurboLabIt\ShopifySdk\Service\ShopifyAdminConnector;
use TurboLabIt\ShopifySdk\ShopifySdkBundle;


class ShopifyAdminConnectorTest extends TestCase
{
    protected function getInstance()
    {
        $kernel = new ShopifySdkTestingKernel("test", true);
        $kernel->boot();
        $container = $kernel->getContainer();

        $connector = $container->get('shopifysdk.admin_connector');
        return $connector;
    }


    public function testInstance()
    {
        $connector = $this->getInstance();
        $this->assertInstanceOf(ShopifyAdminConnector::class, $connector);
    }


    public function testEndpoint()
    {
        $connector  = $this->getInstance();
        $endpoint   = $connector->resolveBaseUrl();
        $this->assertEquals('https://test-borasonet.myshopify.com/admin/api/2023-04/graphql.json', $endpoint);
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
