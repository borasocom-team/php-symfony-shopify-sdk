<?php
namespace TurboLabIt\ShopifySdk\tests;

use TurboLabIt\ShopifySdk\Request\ShopifySmartCollectionListRequest;


class ShopifySmartCollectionListRequestTest extends Base
{
    protected string $serviceId = ShopifySmartCollectionListRequest::class;


    public function testInstance()
    {
        $rq = $this->getInstance();
        $this->assertInstanceOf(ShopifySmartCollectionListRequest::class, $rq);
    }


    public function testListSmartCollections()
    {
        $rq = $this->getInstance();

        $arrCollections = $rq->list();

        $this->assertIsArray($arrCollections);

        foreach($arrCollections as $collection) {
            $this->assertStringContainsString('gid://shopify/Collection/', $collection->id);
            $this->assertNotEmpty($collection->handle);
            $this->assertNotEmpty($collection->title);
        }
    }
}
