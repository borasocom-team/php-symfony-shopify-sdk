<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Bulk PRODUCT-level update via Shopify's `productUpdate`, run as one async bulk operation (staged JSONL →
 * bulkOperationRunMutation → poll → per-row results, all inherited from ShopifyBulkMutationRequest). Surgical
 * sibling of ShopifyProductBulkSetRequest: `productUpdate` patches ONLY the product-level fields the caller
 * supplies (title, tags, status, product metafields, …) and never touches variants/inventory/media — so the
 * delta sync can refresh a title or revive a status without re-declaring the whole product.
 *
 * Each input row is a raw ProductUpdateInput map and MUST carry `id`; every other key is optional and applied
 * verbatim (a key absent → that field left unchanged).
 */
class ShopifyProductBulkUpdateRequest extends ShopifyBulkMutationRequest
{
    const string UPDATE_MUTATION =
        'mutation call($product: ProductUpdateInput!) { productUpdate(product: $product) ' .
        '{ product { id status } userErrors { field message } } }';


    /**
     * @param array $arrProductInputs list of ProductUpdateInput maps, each REQUIRING an `id` (Shopify product GID)
     *                                plus any of title/tags/status/metafields/…
     * @return string[] per-row error messages (empty == every product updated cleanly)
     */
    public function updateMany(array $arrProductInputs) : array
    {
        $arrProductInputs = array_values(array_filter(
            $arrProductInputs,
            fn($input) => !empty($input['id'])
        ));
        if( empty($arrProductInputs) ) {
            return [];
        }

        $arrVariables = array_map(
            fn(array $input) => ['product' => $input],
            $arrProductInputs
        );

        return $this->collectRowErrors($this->run(static::UPDATE_MUTATION, $arrVariables));
    }


    /**
     * The bulk-mutation results JSONL holds one object per executed mutation; its top-level shape is
     * version-dependent, so probe the known candidate paths for per-row userErrors.
     *
     * @return string[]
     */
    protected function collectRowErrors(array $arrLines) : array
    {
        $arrErrors = [];
        foreach($arrLines as $oLine) {

            $arrRowUserErrors =
                $oLine->userErrors
                ?? $oLine->data->productUpdate->userErrors
                ?? $oLine->productUpdate->userErrors
                ?? [];

            foreach($arrRowUserErrors as $oError) {
                $arrErrors[] = $this->formatUserError($oError);
            }

            $arrErrors = array_merge($arrErrors, $this->lineGraphqlErrors($oLine));
        }

        return $arrErrors;
    }
}
