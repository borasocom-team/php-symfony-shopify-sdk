<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Bulk-CREATES new variants on EXISTING products via Shopify's `productVariantsBulkCreate`, run as one async
 * bulk operation (inherited from ShopifyBulkMutationRequest). The delta sync uses it for the "add-to-existing"
 * case: a brand-new caliber appears mid-day on a model whose Shopify product already exists — append the
 * variant without rebuilding the product (creating a brand-new PRODUCT stays the nightly full sync's job).
 *
 * Per-product, like its update sibling: ['productId' => <gid>, 'variants' => [ <ProductVariantsBulkInput>, … ]].
 * Variant rows are passed verbatim (the caller assembles optionValues/inventoryItem/inventoryQuantities); the
 * append `strategy` defaults to DEFAULT (the product must already carry its named option, e.g. "Calibro").
 */
class ShopifyProductVariantsBulkCreateRequest extends ShopifyBulkMutationRequest
{
    const string STRATEGY_DEFAULT = 'DEFAULT';

    const string CREATE_MUTATION =
        'mutation call($productId: ID!, $variants: [ProductVariantsBulkInput!]!, $strategy: ProductVariantsBulkCreateStrategy) ' .
        '{ productVariantsBulkCreate(productId: $productId, variants: $variants, strategy: $strategy) ' .
        '{ product { id } productVariants { id } userErrors { field message } } }';


    /**
     * @param array  $arrByProduct one row per product: ['productId' => <gid>, 'variants' => [ <variant input>, … ]]
     * @param string $strategy     ProductVariantsBulkCreateStrategy (DEFAULT appends to an existing option axis)
     * @return string[] per-row error messages (empty == every variant created cleanly)
     */
    public function createMany(array $arrByProduct, string $strategy = self::STRATEGY_DEFAULT) : array
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
                'strategy'  => $strategy,
            ],
            $arrByProduct
        );

        return $this->collectRowErrors($this->run(static::CREATE_MUTATION, $arrVariables));
    }


    /** @return string[] */
    protected function collectRowErrors(array $arrLines) : array
    {
        $arrErrors = [];
        foreach($arrLines as $oLine) {

            $arrRowUserErrors =
                $oLine->userErrors
                ?? $oLine->data->productVariantsBulkCreate->userErrors
                ?? $oLine->productVariantsBulkCreate->userErrors
                ?? [];

            foreach($arrRowUserErrors as $oError) {
                $arrErrors[] = $this->formatUserError($oError);
            }

            $arrErrors = array_merge($arrErrors, $this->lineGraphqlErrors($oLine));
        }

        return $arrErrors;
    }
}
