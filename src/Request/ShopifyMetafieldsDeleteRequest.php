<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Deletes metafield VALUES from any owner resource via Shopify's `metafieldsDelete` (chunked). Generic: the
 * caller supplies rows of {ownerId, namespace, key} — the definition is untouched, only the value on that owner
 * is removed. Deleting a metafield that doesn't exist on the owner is not an error (Shopify ignores it), so
 * callers may pass candidates without checking existence first.
 *
 * The rows are inlined into the mutation (json_encode-escaped) rather than passed as GraphQL variables,
 * matching ShopifyMetafieldsSetRequest and the rest of the SDK's non-bulk requests.
 */
class ShopifyMetafieldsDeleteRequest extends ShopifyBaseAdminRequest
{
    const int MAX_PER_CALL = 25;


    /**
     * @param array $arrMetafields rows of ['ownerId'=>, 'namespace'=>, 'key'=>]
     * @return string[] per-row error messages (empty == every value deleted cleanly)
     */
    public function deleteMany(array $arrMetafields) : array
    {
        $arrMetafields = array_values($arrMetafields);
        if( empty($arrMetafields) ) {
            return [];
        }

        $arrErrors = [];

        foreach(array_chunk($arrMetafields, static::MAX_PER_CALL) as $arrChunk) {

            $arrRows = array_map(fn(array $mf) => sprintf(
                '{ ownerId: %s, namespace: %s, key: %s }',
                json_encode((string)($mf['ownerId']   ?? ''), JSON_THROW_ON_ERROR),
                json_encode((string)($mf['namespace'] ?? ''), JSON_THROW_ON_ERROR),
                json_encode((string)($mf['key']       ?? ''), JSON_THROW_ON_ERROR)
            ), $arrChunk);

            $mutation = sprintf(
                'mutation { metafieldsDelete(metafields: [%s]) { deletedMetafields { ownerId key } userErrors { field message } } }',
                implode(', ', $arrRows)
            );

            $response  = $this->setQuery($mutation, true)->connector->send($this);
            $oResponse = $this->buildFromResponse($response);

            foreach($oResponse->data->metafieldsDelete->userErrors ?? [] as $oError) {
                $arrErrors[] = $this->formatUserError($oError);
            }
        }

        return $arrErrors;
    }
}
