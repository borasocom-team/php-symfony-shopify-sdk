<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Bulk-updates EXISTING variants via Shopify's `productVariantsBulkUpdate`, run as one async bulk operation
 * (inherited from ShopifyBulkMutationRequest). Surgical: patches only the per-variant fields the caller passes
 * (price, variant metafields, …) on variants that already exist — it never creates/removes variants and never
 * touches the product envelope. The delta sync's price-write primitive.
 *
 * The mutation is per-product (productVariantsBulkUpdate takes one productId + its variants), so the caller
 * supplies one row per product: ['productId' => <gid>, 'variants' => [ <ProductVariantsBulkInput>, … ]], each
 * variant row REQUIRING its own `id` (variant GID).
 */
class ShopifyProductVariantsBulkUpdateRequest extends ShopifyBulkMutationRequest
{
    const string UPDATE_MUTATION =
        'mutation call($productId: ID!, $variants: [ProductVariantsBulkInput!]!) ' .
        '{ productVariantsBulkUpdate(productId: $productId, variants: $variants) ' .
        '{ product { id } userErrors { field message } } }';


    /**
     * @param array $arrByProduct one row per product: ['productId' => <gid>, 'variants' => [ <variant input>, … ]]
     * @return string[] per-row error messages (empty == every variant updated cleanly)
     */
    public function updateMany(array $arrByProduct) : array
    {
        $arrByProduct = array_values(array_filter(
            $arrByProduct,
            fn($row) => !empty($row['productId']) && !empty($row['variants'])
        ));
        if( empty($arrByProduct) ) {
            return [];
        }

        $arrVariables = array_map(
            fn(array $row) => [
                'productId' => (string)$row['productId'],
                'variants'  => array_values($row['variants']),
            ],
            $arrByProduct
        );

        return $this->collectRowErrors($this->run(static::UPDATE_MUTATION, $arrVariables));
    }


    /** @return string[] */
    protected function collectRowErrors(array $arrLines) : array
    {
        $arrErrors = [];
        foreach($arrLines as $oLine) {

            $arrRowUserErrors =
                $oLine->userErrors
                ?? $oLine->data->productVariantsBulkUpdate->userErrors
                ?? $oLine->productVariantsBulkUpdate->userErrors
                ?? [];

            foreach($arrRowUserErrors as $oError) {
                $arrErrors[] = ($oError->field ?? '') . ': ' . ($oError->message ?? '?');
            }
        }

        return $arrErrors;
    }
}
