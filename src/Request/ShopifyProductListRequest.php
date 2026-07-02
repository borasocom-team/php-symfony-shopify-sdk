<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Bulk-reads products from Shopify. Each returned node carries id, status, tags[] (plus whatever extra fields
 * a subclass injects via the `productField` Twig block of the products-bulk template), indexed by Shopify GID.
 * If the subclass block queries the `variants` connection, each variant arrives as a child JSONL line and is
 * re-attached to its product as `->variants[]` (plain array of variant nodes).
 */
class ShopifyProductListRequest extends ShopifyBaseAdminRequest
{
    protected string $templateFile = 'products-bulk';


    public function getAllActive() : array
    {
        return $this->runProductBulkQuery('status:active');
    }


    /**
     * Bulk-read EVERY product regardless of status (active, draft, archived), indexed by Shopify GID. Use this
     * (instead of getAllActive) when you need drafts too — e.g. a full sync that must revive DRAFT products.
     */
    public function getAll() : array
    {
        return $this->runProductBulkQuery('');
    }


    /**
     * Extra Twig variables a subclass wants exposed to the products-bulk template (its `productField` block) — e.g.
     * a list of publication ids to probe per product. Empty by default; merged into the render data below. Must not
     * reuse the reserved `shopifyProductsQuery` / `shopify_config` keys.
     */
    protected function extraBulkTemplateData() : array
    {
        return [];
    }


    protected function runProductBulkQuery(string $shopifyProductsQuery) : array
    {
        $response =
            $this
                ->setQueryFromTemplate(['shopifyProductsQuery' => $shopifyProductsQuery] + $this->extraBulkTemplateData(), null, true)
                ->connector->send($this);

        // buildFromBulkResponse() throws on bulkOperationRunQuery userErrors, polls node(id:), downloads the JSONL
        $arrLines = $this->buildFromBulkResponse($response, true);

        // Top-level JSONL lines are Product nodes. A subclass template may add nested connections (e.g.
        // variants): those arrive as separate child lines carrying __parentId — Shopify guarantees the parent
        // line precedes its children, so a single pass can re-attach them.
        $arrProducts = [];
        foreach($arrLines as $node) {

            $parentId = (string)($node->__parentId ?? '');

            if( $parentId !== '' ) {
                // child of a nested connection → re-attach ProductVariant rows under their product
                if( isset($arrProducts[$parentId]) && stripos((string)($node->id ?? ''), '/ProductVariant/') !== false ) {
                    $arrProducts[$parentId]->variants   ??= [];
                    $arrProducts[$parentId]->variants[] = $node;
                }
                continue;
            }

            if( empty($node->id) || stripos($node->id, '/Product/') === false ) {
                continue;
            }

            $arrProducts[$node->id] = $node;
        }

        return $arrProducts;
    }
}
