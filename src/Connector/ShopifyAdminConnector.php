<?php
namespace TurboLabIt\ShopifySdk\Connector;


class ShopifyAdminConnector extends ShopifyBaseConnector
{
    protected string $endpoint = 'https://##shop-name##.myshopify.com/admin/api/##api-version##/graphql.json';
}
