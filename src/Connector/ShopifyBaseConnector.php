<?php
namespace TurboLabIt\ShopifySdk\Connector;

use Saloon\Http\Connector;


abstract class ShopifyBaseConnector extends Connector
{
    protected string $endpoint = '';


    public function __construct(protected array $arrConfig)
    { }


    public function defaultConfig(): array
    {
        return [
            'timeout' => 15,
        ];
    }


    public function resolveBaseUrl() : string
    {
        $arrData = [
            "##shop-name##"     => $this->arrConfig["shop_name"],
            "##api-version##"   => $this->arrConfig["api_version"],
        ];

        $endpoint = str_ireplace(array_keys($arrData), array_values($arrData), $this->endpoint);
        return $endpoint;
    }


    protected function defaultHeaders(): array
    {
        return [
            'Content-Type'              => 'application/graphql',
            'X-Shopify-Access-Token'    => $this->arrConfig["access_token"]
        ];
    }
}
