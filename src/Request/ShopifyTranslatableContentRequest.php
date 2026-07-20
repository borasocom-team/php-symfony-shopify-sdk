<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Reads translatable resources via `translatableResourcesByIds` — their CANONICAL translation digests
 * (getDigestsByResourceIds) and/or the TRANSLATED values already stored on them (getTranslationsByResourceIds).
 * Works for ANY translatable resource (products, collections, metafields, metaobjects, …) and mixed GID types in
 * one call.
 *
 * ⚠️ For metafields, the digest is NOT the same hash as `Metafield.compareDigest` (which serves metafieldsSet's
 * optimistic locking) — translations MUST use the digest read here. Digests rotate whenever the base content is
 * written, so re-read them after any write to the resources being translated.
 *
 * ⚠️ A resource exposes translatable content only for the fields that actually HOLD a value (an empty field is
 * absent from `translatableContent`, hence untranslatable) — and, for metaobjects, only while their definition
 * carries the translatable capability (ShopifyMetaobjectRequest::ensureDefinition).
 *
 * Requires the `read_translations` access scope.
 */
class ShopifyTranslatableContentRequest extends ShopifyBaseAdminRequest
{
    /** Resource GIDs per request (`first` must cover the chunk — one page per chunk, no cursor walking). */
    const int MAX_RESOURCES_PER_CALL = 100;

    /** Same, for the translations read: each resource carries one aliased `translations(locale:)` selection per
     *  requested locale, so the chunk is smaller to keep the query cost within Shopify's per-request budget. */
    const int MAX_TRANSLATED_RESOURCES_PER_CALL = 25;


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


    /**
     * The TRANSLATED values already registered on the given resources, one aliased `translations(locale:)`
     * selection per locale. Read-side counterpart of ShopifyTranslationsRegisterRequest — for translations a
     * MERCHANT maintains in the admin (Translate & Adapt) and the app only consumes.
     *
     * A locale the shop has NOT enabled is not an error: it simply yields no translations (verified live), so
     * callers may pass a static language list without pre-intersecting the shop locales. Empty values are
     * dropped, so "present in the result" always means "has a usable translation".
     *
     * @param string[] $arrResourceGids translatable resource GIDs (mixed types allowed)
     * @param string[] $arrLocales      locale codes (e.g. ['en', 'fr'])
     * @return array<string,array<string,array<string,string>>> [resource GID => [locale => [content key => value]]]
     *                                                          — resources/locales/keys without a translation are absent
     */
    public function getTranslationsByResourceIds(array $arrResourceGids, array $arrLocales) : array
    {
        $arrResourceGids = array_values(array_unique(array_map('strval', $arrResourceGids)));
        $arrLocales      = array_values(array_unique(array_map('strval', $arrLocales)));
        if( empty($arrResourceGids) || empty($arrLocales) ) {
            return [];
        }

        // the locale is interpolated into an ALIAS, so it must be a plain language tag (no injection surface)
        foreach($arrLocales as $locale) {
            if( preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $locale) !== 1 ) {
                throw new \InvalidArgumentException("Invalid locale code \"$locale\"");
            }
        }

        $arrResult = [];

        foreach(array_chunk($arrResourceGids, static::MAX_TRANSLATED_RESOURCES_PER_CALL) as $arrChunk) {

            $arrSelections = array_map(
                fn(string $locale) => sprintf('%s: translations(locale: %s) { key value }',
                    $this->localeAlias($locale), json_encode($locale, JSON_THROW_ON_ERROR)),
                $arrLocales
            );

            $query = sprintf(
                'query { translatableResourcesByIds(first: %d, resourceIds: %s) { edges { node { resourceId %s } } } }',
                count($arrChunk), json_encode($arrChunk, JSON_THROW_ON_ERROR), implode(' ', $arrSelections)
            );

            $response  = $this->setQuery($query, true)->connector->send($this);
            $oResponse = $this->buildFromResponse($response);

            foreach($oResponse->data->translatableResourcesByIds->edges ?? [] as $oEdge) {
                $resourceId = (string)($oEdge->node->resourceId ?? '');
                if( $resourceId === '' ) {
                    continue;
                }
                foreach($arrLocales as $locale) {
                    foreach($oEdge->node->{$this->localeAlias($locale)} ?? [] as $oTranslation) {
                        $value = (string)($oTranslation->value ?? '');
                        if( !empty($oTranslation->key) && $value !== '' ) {
                            $arrResult[$resourceId][$locale][(string)$oTranslation->key] = $value;
                        }
                    }
                }
            }
        }

        return $arrResult;
    }


    /** GraphQL field alias for one locale — a locale code can carry a `-` (pt-BR), which an alias cannot. */
    protected function localeAlias(string $locale) : string
    {
        return 'loc_' . str_replace('-', '_', $locale);
    }
}
