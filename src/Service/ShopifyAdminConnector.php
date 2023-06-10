<?php
namespace TurboLabIt\ShopifySdk\Service;

use Saloon\Http\Connector;


class ShopifyAdminConnector extends Connector
{
    protected string $endpoint = 'https://##shop-name##.myshopify.com/admin/api/##api-version##/graphql.json';


    public function __construct(protected array $arrConfig)
    { }


    public function resolveBaseUrl(): string
    {
        $arrData = [
            "##shop-name##"     => $this->arrConfig["shop_name"],
            "##api-version##"   => $this->arrConfig["api_version"],
        ];

        $endpoint = str_ireplace(array_keys($arrData), array_values($arrData), $this->endpoint);
        return $endpoint;
    }
}
