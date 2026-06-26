<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Sets metafield VALUES on any owner resource via Shopify's `metafieldsSet` (≤25 per call). Generic: the caller
 * supplies rows of {ownerId, namespace, key, type, value} — value is ALWAYS a string per Shopify, regardless of
 * the metafield type. `type` may be omitted once a definition exists for that namespace/key/owner (Shopify
 * derives it). Requires the owner resource's write scope (e.g. write_markets for Market owners).
 *
 * The rows are inlined into the mutation (json_encode-escaped, like getDefinitionType) rather than passed as
 * GraphQL variables, matching the rest of the SDK's non-bulk requests.
 */
class ShopifyMetafieldsSetRequest extends ShopifyBaseAdminRequest
{
    const int MAX_PER_CALL = 25;


    /**
     * @param array $arrMetafields rows of ['ownerId'=>, 'namespace'=>, 'key'=>, 'type'=>, 'value'=>]
     * @return string[] per-row error messages (empty == every value set cleanly)
     */
    public function setMany(array $arrMetafields) : array
    {
        $arrMetafields = array_values($arrMetafields);
        if( empty($arrMetafields) ) {
            return [];
        }

        $arrErrors = [];

        foreach(array_chunk($arrMetafields, static::MAX_PER_CALL) as $arrChunk) {

            $arrRows = array_map(fn(array $mf) => sprintf(
                '{ ownerId: %s, namespace: %s, key: %s, type: %s, value: %s }',
                json_encode((string)($mf['ownerId']   ?? ''), JSON_THROW_ON_ERROR),
                json_encode((string)($mf['namespace'] ?? ''), JSON_THROW_ON_ERROR),
                json_encode((string)($mf['key']       ?? ''), JSON_THROW_ON_ERROR),
                json_encode((string)($mf['type']      ?? ''), JSON_THROW_ON_ERROR),
                json_encode((string)($mf['value']     ?? ''), JSON_THROW_ON_ERROR)
            ), $arrChunk);

            $mutation = sprintf(
                'mutation { metafieldsSet(metafields: [%s]) { metafields { id } userErrors { field message } } }',
                implode(', ', $arrRows)
            );

            $response  = $this->setQuery($mutation, true)->connector->send($this);
            $oResponse = $this->buildFromResponse($response);

            foreach($oResponse->data->metafieldsSet->userErrors ?? [] as $oError) {
                $arrErrors[] = ($oError->field ?? '') . ': ' . ($oError->message ?? '?');
            }
        }

        return $arrErrors;
    }
}
