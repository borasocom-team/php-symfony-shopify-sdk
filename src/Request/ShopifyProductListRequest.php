<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Bulk-reads products from Shopify. Each returned node carries id, status, tags[] (plus whatever extra fields
 * a subclass injects via the `productField` Twig block of the products-bulk template), indexed by Shopify GID.
 */
class ShopifyProductListRequest extends ShopifyBaseAdminRequest
{
    protected string $templateFile = 'products-bulk';


    public function getAllActive() : array
    {
        return $this->runProductBulkQuery('status:active');
    }


    protected function runProductBulkQuery(string $shopifyProductsQuery) : array
    {
        $response =
            $this
                ->setQueryFromTemplate(['shopifyProductsQuery' => $shopifyProductsQuery], null, true)
                ->connector->send($this);

        // buildFromBulkResponse() throws on bulkOperationRunQuery userErrors, polls node(id:), downloads the JSONL
        $arrLines = $this->buildFromBulkResponse($response, true);

        // our query has no nested connections, so each JSONL line is a Product node
        $arrProducts = [];
        foreach($arrLines as $node) {

            if( empty($node->id) || stripos($node->id, '/Product/') === false ) {
                continue;
            }

            $arrProducts[$node->id] = $node;
        }

        return $arrProducts;
    }
}
