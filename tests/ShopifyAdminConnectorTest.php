<?php
namespace TurboLabIt\ShopifySdk\tests;

use TurboLabIt\ShopifySdk\Service\ShopifyAdminConnector;


class ShopifyAdminConnectorTest extends Base
{
    protected string $serviceId = 'shopifysdk.admin_connector';

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
