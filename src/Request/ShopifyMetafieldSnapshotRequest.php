<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Reads a SNAPSHOT of specific metafields — GID, current value and `compareDigest` — for a set of owner
 * resources, via alias-batched `node()` lookups (no bulk operation, no pagination). The `compareDigest` is the
 * digest `translationsRegister` requires as `translatableContentDigest` when translating a metafield's value:
 * ⚠️ it is NOT reproducible client-side (it is not a plain sha256 of the value), so it MUST be read like this,
 * and re-read after every write to the metafield (a value change rotates the digest).
 *
 * Generic: the caller supplies the owner GIDs, the namespace, the keys and the owner's GraphQL typename
 * (the inline-fragment target — e.g. Product, Collection, ProductVariant).
 */
class ShopifyMetafieldSnapshotRequest extends ShopifyBaseAdminRequest
{
    /** Owners per request: each owner expands to one aliased node() with one aliased metafield per key. */
    const int MAX_OWNERS_PER_CALL = 20;


    /**
     * @param string[] $arrOwnerGids  owner resource GIDs (all of the same $ownerTypename)
     * @param string   $namespace     metafield namespace
     * @param string[] $arrKeys       metafield keys to snapshot
     * @param string   $ownerTypename GraphQL typename of the owners (inline-fragment target)
     *
     * @return array<string,array<string,\stdClass>> [ownerGid => [key => { id, value, compareDigest }]] —
     *                                               a metafield the owner doesn't carry is simply absent
     */
    public function getByOwners(array $arrOwnerGids, string $namespace, array $arrKeys, string $ownerTypename = 'Product') : array
    {
        $arrOwnerGids = array_values(array_unique(array_map('strval', $arrOwnerGids)));
        $arrKeys      = array_values($arrKeys);
        if( empty($arrOwnerGids) || empty($arrKeys) ) {
            return [];
        }

        if( !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $ownerTypename) ) {
            throw new \InvalidArgumentException("invalid GraphQL typename: $ownerTypename");
        }

        $arrMetafieldFields = [];
        foreach($arrKeys as $i => $key) {
            $arrMetafieldFields[] = sprintf(
                'k%d: metafield(namespace: %s, key: %s) { id value compareDigest }',
                $i, json_encode($namespace, JSON_THROW_ON_ERROR), json_encode((string)$key, JSON_THROW_ON_ERROR)
            );
        }
        $metafieldFields = implode(' ', $arrMetafieldFields);

        $arrResult = [];

        foreach(array_chunk($arrOwnerGids, static::MAX_OWNERS_PER_CALL) as $arrChunk) {

            $arrAliases = [];
            foreach($arrChunk as $i => $ownerGid) {
                $arrAliases[] = sprintf(
                    'o%d: node(id: %s) { ... on %s { %s } }',
                    $i, json_encode($ownerGid, JSON_THROW_ON_ERROR), $ownerTypename, $metafieldFields
                );
            }

            $query     = 'query { ' . implode(' ', $arrAliases) . ' }';
            $response  = $this->setQuery($query, true)->connector->send($this);
            $oResponse = $this->buildFromResponse($response);

            foreach($arrChunk as $i => $ownerGid) {
                $oNode = $oResponse->data->{"o$i"} ?? null;
                if( $oNode === null ) {
                    continue;   // owner GID not found → absent from the result, caller's call
                }
                foreach($arrKeys as $k => $key) {
                    $oMetafield = $oNode->{"k$k"} ?? null;
                    if( $oMetafield !== null && !empty($oMetafield->id) ) {
                        $arrResult[$ownerGid][$key] = $oMetafield;
                    }
                }
            }
        }

        return $arrResult;
    }
}
