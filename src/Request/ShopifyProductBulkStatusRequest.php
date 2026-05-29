<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Sets product `status` in bulk (DRAFT to hide, ACTIVE to revive) via Shopify's async bulk mutation,
 * built on top of the generic ShopifyBulkMutationRequest.
 */
class ShopifyProductBulkStatusRequest extends ShopifyBulkMutationRequest
{
    const string STATUS_DRAFT  = 'DRAFT';
    const string STATUS_ACTIVE = 'ACTIVE';

    /**
     * 2024-10+ signature (`product: ProductUpdateInput!`); if a shop's API version still requires the legacy
     * `input: ProductInput!`, change this constant (and the JSONL key built in setStatus()).
     */
    const string STATUS_MUTATION =
        'mutation call($product: ProductUpdateInput!) { productUpdate(product: $product) ' .
        '{ product { id status } userErrors { field message } } }';


    /** @return string[] per-row error messages (empty array == every product updated) */
    public function draft(array $arrProductGids) : array     { return $this->setStatus($arrProductGids, static::STATUS_DRAFT); }

    /** @return string[] per-row error messages (empty array == every product updated) */
    public function activate(array $arrProductGids) : array   { return $this->setStatus($arrProductGids, static::STATUS_ACTIVE); }


    /** @return string[] per-row error messages (empty array == every product updated) */
    public function setStatus(array $arrProductGids, string $status) : array
    {
        $arrProductGids = array_values(array_unique(array_filter($arrProductGids)));
        if( empty($arrProductGids) ) {
            return [];
        }

        $arrVariables = array_map(
            fn($gid) => ['product' => ['id' => $gid, 'status' => $status]],
            $arrProductGids
        );

        return $this->collectRowErrors($this->run(static::STATUS_MUTATION, $arrVariables));
    }


    /**
     * The bulk-mutation results JSONL holds one object per executed mutation; its exact top-level shape is
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
                $arrErrors[] = ($oError->field ?? '') . ': ' . ($oError->message ?? '?');
            }
        }

        return $arrErrors;
    }
}
