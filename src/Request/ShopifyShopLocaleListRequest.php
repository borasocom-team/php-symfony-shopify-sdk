<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Reads the shop's enabled locales (`shopLocales`): every language the merchant added in the admin, published
 * on the storefront or not. Translations (`translationsRegister`) are only valid for locales in this list.
 * Requires the `read_locales` (or `read_markets_home`) access scope — without it the query throws a
 * ShopifyResponseException (ACCESS_DENIED), which callers may catch to degrade gracefully.
 */
class ShopifyShopLocaleListRequest extends ShopifyBaseAdminRequest
{
    /**
     * @return \stdClass[] one per locale: { locale ("en", "pt-BR", …), name, primary (bool), published (bool) }
     */
    public function getAll() : array
    {
        $cacheKey = $this->buildCacheKey(__FUNCTION__);
        if( isset($this->arrCachedData[$cacheKey]) ) {
            return $this->arrCachedData[$cacheKey];
        }

        $response  = $this->setQuery('query { shopLocales { locale name primary published } }', true)->connector->send($this);
        $oResponse = $this->buildFromResponse($response);

        return $this->arrCachedData[$cacheKey] = (array)($oResponse->data->shopLocales ?? []);
    }


    /** The enabled locale CODES only (primary included) — the valid `locale` values for translationsRegister. */
    public function getLocaleCodes() : array
    {
        return array_map(fn(\stdClass $oLocale) => (string)$oLocale->locale, $this->getAll());
    }
}
