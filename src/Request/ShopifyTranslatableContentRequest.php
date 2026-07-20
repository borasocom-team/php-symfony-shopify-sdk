<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Reads the CANONICAL translation digests of translatable resources, via `translatableResourcesByIds`: for each
 * resource GID, its translatable content keys and their `digest` — the value `translationsRegister` requires as
 * `translatableContentDigest`. Works for ANY translatable resource (products, collections, metafields, …) and
 * mixed GID types in one call.
 *
 * ⚠️ For metafields, this digest is NOT the same hash as `Metafield.compareDigest` (which serves metafieldsSet's
 * optimistic locking) — translations MUST use the digest read here. Digests rotate whenever the base content is
 * written, so re-read them after any write to the resources being translated.
 *
 * Requires the `read_translations` access scope.
 */
class ShopifyTranslatableContentRequest extends ShopifyBaseAdminRequest
{
    /** Resource GIDs per request (`first` must cover the chunk — one page per chunk, no cursor walking). */
    const int MAX_RESOURCES_PER_CALL = 100;


    /**
     * @param string[] $arrResourceGids translatable resource GIDs (mixed types allowed)
     * @return array<string,array<string,string>> [resource GID => [content key => digest]] — a resource with no
     *                                            translatable content (or not found) is simply absent
     */
    public function getDigestsByResourceIds(array $arrResourceGids) : array
    {
        $arrResourceGids = array_values(array_unique(array_map('strval', $arrResourceGids)));
        if( empty($arrResourceGids) ) {
            return [];
        }

        $arrResult = [];

        foreach(array_chunk($arrResourceGids, static::MAX_RESOURCES_PER_CALL) as $arrChunk) {

            $query = sprintf(
                'query { translatableResourcesByIds(first: %d, resourceIds: %s) { edges { node { ' .
                'resourceId translatableContent { key digest } } } } }',
                count($arrChunk), json_encode($arrChunk, JSON_THROW_ON_ERROR)
            );

            $response  = $this->setQuery($query, true)->connector->send($this);
            $oResponse = $this->buildFromResponse($response);

            foreach($oResponse->data->translatableResourcesByIds->edges ?? [] as $oEdge) {
                $resourceId = (string)($oEdge->node->resourceId ?? '');
                if( $resourceId === '' ) {
                    continue;
                }
                foreach($oEdge->node->translatableContent ?? [] as $oContent) {
                    if( !empty($oContent->key) ) {
                        $arrResult[$resourceId][(string)$oContent->key] = (string)($oContent->digest ?? '');
                    }
                }
            }
        }

        return $arrResult;
    }
}
