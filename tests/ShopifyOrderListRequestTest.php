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
        $rqOrders = $this->getInstance();

        $data = $rqOrders->getRecent();

    }
}
