<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Registers (create-or-update) and removes TRANSLATIONS on any translatable resource via Shopify's
 * `translationsRegister` / `translationsRemove`, alias-batched (multiple resources per request, no bulk
 * operation — neither mutation is bulk-op eligible, they take two top-level arguments).
 *
 * Contract notes:
 *   - each registered translation needs the CURRENT `translatableContentDigest` of the content being translated
 *     (for a metafield's value: the metafield's `compareDigest` — see ShopifyMetafieldSnapshotRequest). A stale
 *     digest is rejected with a userError; re-read the digest after any write to the base content.
 *   - `locale` must be one of the shop's enabled locales (`shopLocales` — ShopifyShopLocaleListRequest);
 *     unpublished (alternate) locales are valid targets.
 *   - requires the `write_translations` access scope.
 *
 * Per-row failures surface as formatted error strings (formatUserError idiom); the resources that had at least
 * one failed registration are additionally exposed via getLastFailedResourceIds(), so callers maintaining a
 * written-state gate can withhold it for exactly the failed resources.
 */
class ShopifyTranslationsRegisterRequest extends ShopifyBaseAdminRequest
{
    /** Resources per request (each may carry several translations — e.g. one per locale). */
    const int MAX_REGISTER_PER_CALL = 10;
    const int MAX_REMOVE_PER_CALL   = 25;

    /** @var array<string,true> resource GIDs with ≥1 userError in the LAST registerMany() call */
    protected array $arrLastFailedResourceIds = [];


    /**
     * @param array $arrResources rows of ['resourceId' => GID,
     *                            'translations' => [['locale'=>, 'key'=>, 'value'=>, 'digest'=>], …]]
     * @return string[] per-translation error messages (empty == everything registered cleanly)
     */
    public function registerMany(array $arrResources) : array
    {
        $this->arrLastFailedResourceIds = [];

        // a resource with no translations to write is a no-op row, not an error
        $arrResources = array_values(array_filter($arrResources, fn(array $r) => !empty($r['translations'])));
        if( empty($arrResources) ) {
            return [];
        }

        $arrErrors = [];

        foreach(array_chunk($arrResources, static::MAX_REGISTER_PER_CALL) as $arrChunk) {

            $arrAliases = [];
            foreach($arrChunk as $i => $arrResource) {

                $arrRows = array_map(fn(array $t) => sprintf(
                    '{ locale: %s, key: %s, value: %s, translatableContentDigest: %s }',
                    json_encode((string)($t['locale'] ?? ''), JSON_THROW_ON_ERROR),
                    json_encode((string)($t['key']    ?? ''), JSON_THROW_ON_ERROR),
                    json_encode((string)($t['value']  ?? ''), JSON_THROW_ON_ERROR),
                    json_encode((string)($t['digest'] ?? ''), JSON_THROW_ON_ERROR)
                ), $arrResource['translations']);

                $arrAliases[] = sprintf(
                    'r%d: translationsRegister(resourceId: %s, translations: [%s]) { userErrors { field message } }',
                    $i, json_encode((string)$arrResource['resourceId'], JSON_THROW_ON_ERROR), implode(', ', $arrRows)
                );
            }

            $mutation  = 'mutation { ' . implode(' ', $arrAliases) . ' }';
            $response  = $this->setQuery($mutation, true)->connector->send($this);
            $oResponse = $this->buildFromResponse($response);

            foreach($arrChunk as $i => $arrResource) {
                foreach($oResponse->data->{"r$i"}->userErrors ?? [] as $oError) {
                    $arrErrors[] = $arrResource['resourceId'] . ' → ' . $this->formatUserError($oError);
                    $this->arrLastFailedResourceIds[(string)$arrResource['resourceId']] = true;
                }
            }
        }

        return $arrErrors;
    }


    /** Resource GIDs that had at least one failed translation in the last registerMany() call. */
    public function getLastFailedResourceIds() : array
    {
        return array_keys($this->arrLastFailedResourceIds);
    }


    /**
     * @param array $arrRemovals rows of ['resourceId' => GID, 'locales' => string[], 'keys' => string[]]
     * @return string[] per-row error messages (removing an already-absent translation is not an error)
     */
    public function removeMany(array $arrRemovals) : array
    {
        $arrRemovals = array_values(array_filter($arrRemovals, fn(array $r) => !empty($r['locales']) && !empty($r['keys'])));
        if( empty($arrRemovals) ) {
            return [];
        }

        $arrErrors = [];

        foreach(array_chunk($arrRemovals, static::MAX_REMOVE_PER_CALL) as $arrChunk) {

            $arrAliases = [];
            foreach($arrChunk as $i => $arrRemoval) {
                $arrAliases[] = sprintf(
                    'r%d: translationsRemove(resourceId: %s, locales: %s, translationKeys: %s) { userErrors { field message } }',
                    $i,
                    json_encode((string)$arrRemoval['resourceId'], JSON_THROW_ON_ERROR),
                    json_encode(array_values(array_map('strval', $arrRemoval['locales'])), JSON_THROW_ON_ERROR),
                    json_encode(array_values(array_map('strval', $arrRemoval['keys'])), JSON_THROW_ON_ERROR)
                );
            }

            $mutation  = 'mutation { ' . implode(' ', $arrAliases) . ' }';
            $response  = $this->setQuery($mutation, true)->connector->send($this);
            $oResponse = $this->buildFromResponse($response);

            foreach($arrChunk as $i => $arrRemoval) {
                foreach($oResponse->data->{"r$i"}->userErrors ?? [] as $oError) {
                    $arrErrors[] = $arrRemoval['resourceId'] . ' → ' . $this->formatUserError($oError);
                }
            }
        }

        return $arrErrors;
    }
}
