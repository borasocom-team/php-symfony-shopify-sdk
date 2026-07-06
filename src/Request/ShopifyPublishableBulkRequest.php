<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Publishes/unpublishes publishables (products, collections) to/from publications in bulk, via Shopify's
 * `publishablePublish` / `publishableUnpublish` run as ONE async bulk operation (staged JSONL →
 * bulkOperationRunMutation → poll → per-row results, all inherited from ShopifyBulkMutationRequest).
 * One row per publishable, each with its OWN publication set — so a mass backfill (thousands of products,
 * each targeting its channels/catalogs) costs one operation instead of a synchronous mutation per product.
 *
 * Publish and unpublish are distinct mutations, so a mixed reconcile takes two calls (a bulk op runs a
 * single mutation string). Rows are processed independently server-side: a failing row surfaces in the
 * results as that row's userErrors and never blocks the others.
 */
class ShopifyPublishableBulkRequest extends ShopifyBulkMutationRequest
{
    const string PUBLISH_MUTATION =
        'mutation call($id: ID!, $input: [PublicationInput!]!) { publishablePublish(id: $id, input: $input) ' .
        '{ userErrors { field message } } }';

    const string UNPUBLISH_MUTATION =
        'mutation call($id: ID!, $input: [PublicationInput!]!) { publishableUnpublish(id: $id, input: $input) ' .
        '{ userErrors { field message } } }';


    /**
     * @param array<string,string[]> $arrPublicationIdsByGid publishable GID => publication GIDs to publish it to
     * @return string[] per-row error messages (empty == every publishable published cleanly)
     */
    public function publishMany(array $arrPublicationIdsByGid) : array
        { return $this->applyMany(static::PUBLISH_MUTATION, 'publishablePublish', $arrPublicationIdsByGid); }


    /**
     * @param array<string,string[]> $arrPublicationIdsByGid publishable GID => publication GIDs to pull it from
     * @return string[] per-row error messages (empty == every publishable unpublished cleanly)
     */
    public function unpublishMany(array $arrPublicationIdsByGid) : array
        { return $this->applyMany(static::UNPUBLISH_MUTATION, 'publishableUnpublish', $arrPublicationIdsByGid); }


    /** @return string[] */
    protected function applyMany(string $mutation, string $mutationField, array $arrPublicationIdsByGid) : array
    {
        $arrVariables = [];
        foreach($arrPublicationIdsByGid as $gid => $arrPublicationIds) {

            $arrPublicationIds = array_values(array_unique(array_filter((array)$arrPublicationIds)));
            if( empty($gid) || empty($arrPublicationIds) ) {
                continue;
            }

            $arrVariables[] = [
                'id'    => (string)$gid,
                'input' => array_map(fn(string $pubId) => ['publicationId' => $pubId], $arrPublicationIds),
            ];
        }

        return $this->collectRowErrors($this->run($mutation, $arrVariables), $mutationField);
    }


    /**
     * The bulk-mutation results JSONL holds one object per executed mutation; its top-level shape is
     * version-dependent, so probe the known candidate paths for per-row userErrors.
     *
     * @return string[]
     */
    protected function collectRowErrors(array $arrLines, string $mutationField) : array
    {
        $arrErrors = [];
        foreach($arrLines as $oLine) {

            $arrRowUserErrors =
                $oLine->userErrors
                ?? $oLine->data->{$mutationField}->userErrors
                ?? $oLine->{$mutationField}->userErrors
                ?? [];

            foreach($arrRowUserErrors as $oError) {
                $arrErrors[] = $this->formatUserError($oError);
            }
        }

        return $arrErrors;
    }
}
