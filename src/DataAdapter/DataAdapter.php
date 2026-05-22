<?php

namespace TurboLabIt\ShopifySdk\DataAdapter;

class DataAdapter
{
    public function shortShopifyId(\stdClass $responseObject) : string
    {
        $id = (string)($responseObject->id ?? '');

        return (string)preg_replace('#^gid://shopify/[^/]+/#i', '', $id);
    }
}
