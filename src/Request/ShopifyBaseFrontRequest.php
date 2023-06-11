<?php
namespace TurboLabIt\ShopifySdk\Request;

use Twig\Environment;
use TurboLabIt\ShopifySdk\Connector\ShopifyFrontConnector;


abstract class ShopifyBaseFrontRequest extends BaseRequest
{
    public function __construct(protected Environment $twig, protected ShopifyFrontConnector $connector)
    { }
}
