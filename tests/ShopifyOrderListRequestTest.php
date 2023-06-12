<?php
namespace TurboLabIt\ShopifySdk\tests;

use TurboLabIt\ShopifySdk\Request\ShopifyOrderListRequest;


class ShopifyOrderListRequestTest extends Base
{
    protected string $serviceId = ShopifyOrderListRequest::class;

    public function testInstance()
    {
        $rqOrders = $this->getInstance();
        $this->assertInstanceOf(ShopifyOrderListRequest::class, $rqOrders);
    }


    public function testBasicData()
    {
        $rqOrders = $this->getInstance();

        //
        $this->assertEquals('', $rqOrders->resolveEndpoint());
    }


    public function testGetNewOrders()
    {
        $rqOrders   = $this->getInstance();
        $arrOrders  = $rqOrders->getRecent(7305);
        $this->assertNotEmpty($arrOrders);

        foreach($arrOrders as $order) {

            $this->assertStringContainsString('gid://shopify/Order/', $order["id"]);
            $this->assertStringContainsString('@', $order["email"]);
        }
    }
}
