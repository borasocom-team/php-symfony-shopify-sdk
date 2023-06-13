<?php
namespace TurboLabIt\ShopifySdk\tests;

use TurboLabIt\ShopifySdk\Connector\ShopifyAdminConnector;


class ShopifyAdminConnectorTest extends Base
{
    protected string $serviceId = 'shopifysdk.admin_connector';

    public function testInstance()
    {
        $connector = $this->getInstance();
        $this->assertInstanceOf(ShopifyAdminConnector::class, $connector);
    }


    public function testBasicData()
    {
        $connector = $this->getInstance();

        //
        $this->assertStringContainsString('.myshopify.com/admin/api/2023-04/graphql.json', $connector->resolveBaseUrl());
        $this->assertEquals(15, $connector->defaultConfig()['timeout']);
    }
}
