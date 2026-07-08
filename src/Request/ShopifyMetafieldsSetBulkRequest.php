<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Writes metafields in bulk via Shopify's `metafieldsSet`, run as ONE async bulk operation (staged JSONL →
 * bulkOperationRunMutation → poll → per-row results, all inherited from ShopifyBulkMutationRequest).
 * `metafieldsSet` accepts at most 25 metafields per invocation, so the flat input list is chunked into
 * JSONL rows of up to 25 — a mass write (tens of thousands of values) still costs a single operation.
 *
 * Same input contract as the synchronous ShopifyMetafieldsSetRequest::setMany(), so callers can switch
 * between the two based on scale.
 */
class ShopifyMetafieldsSetBulkRequest extends ShopifyBulkMutationRequest
{
    /** metafieldsSet hard cap on metafields per invocation. */
    const int MAX_METAFIELDS_PER_CALL = 25;

    const string SET_MUTATION =
        'mutation call($metafields: [MetafieldsSetInput!]!) { metafieldsSet(metafields: $metafields) ' .
        '{ userErrors { field message } } }';


    /**
     * @param array $arrMetafields flat rows of ['ownerId'=>, 'namespace'=>, 'key'=>, 'type'=>, 'value'=>]
     *                             (value ALWAYS a string per Shopify, regardless of type)
     * @return string[] per-row error messages (empty == every metafield written cleanly)
     */
    public function setMany(array $arrMetafields) : array
    {
        $arrMetafields = array_values(array_filter(
            $arrMetafields,
            fn($mf) => !empty($mf['ownerId']) && !empty($mf['key'])
        ));
        if( empty($arrMetafields) ) {
            return [];
        }

        $arrVariables = array_map(
            fn(array $arrChunk) => ['metafields' => $arrChunk],
            array_chunk($arrMetafields, static::MAX_METAFIELDS_PER_CALL)
        );

        return $this->collectRowErrors($this->run(static::SET_MUTATION, $arrVariables));
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
                ?? $oLine->data->metafieldsSet->userErrors
                ?? $oLine->metafieldsSet->userErrors
                ?? [];

            foreach($arrRowUserErrors as $oError) {
                $arrErrors[] = $this->formatUserError($oError);
            }

            $arrErrors = array_merge($arrErrors, $this->lineGraphqlErrors($oLine));
        }

        return $arrErrors;
    }
}
