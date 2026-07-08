<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Bulk create+update (upsert) of products via Shopify's `productSet` mutation, run as a single async bulk
 * operation (staged JSONL → bulkOperationRunMutation → poll by GID → per-row results, all inherited from
 * ShopifyBulkMutationRequest). Sibling of ShopifyProductBulkStatusRequest: that one bulk-sets `status`, this
 * one bulk-sets the whole product.
 *
 * `productSet` upserts: a row WITH `id` updates, a row WITHOUT creates. setMany() takes generic product
 * descriptors (see buildSetInput()) — title, vendor, productType, tags, status, arbitrary metafields, media
 * (product `files` + per-variant `file`, as raw FileSetInput rows), and EITHER a single default-option variant
 * (legacy "1 SKU = 1 product" shape, top-level sku/price/...) OR a list of variants under a named option (e.g.
 * "Calibro") via the `variants` + `optionName` keys. It carries no domain knowledge (e.g. which metafields mean
 * what, what the option axis means, where image URLs come from): the caller supplies the descriptors.
 *
 * ⚠️ The exact ProductVariantSetInput shape (SKU under `inventoryItem`, `inventoryQuantities` at a location,
 * the reserved `Title`/`Default Title` default option, the named-option `productOptions`/`optionValues` pairing)
 * is API-version sensitive. It is assembled in ONE place (buildSetInput) so a staging run / validate_graphql can
 * pin any field drift in a single edit.
 */
class ShopifyProductBulkSetRequest extends ShopifyBulkMutationRequest
{
    const string STATUS_ACTIVE = 'ACTIVE';
    const string STATUS_DRAFT  = 'DRAFT';

    /** A "1 SKU = 1 product" import → one default option/variant; this is its reserved Shopify naming. */
    const string DEFAULT_OPTION_NAME  = 'Title';
    const string DEFAULT_OPTION_VALUE = 'Default Title';

    const string INVENTORY_STATE_AVAILABLE = 'available';

    const string SET_MUTATION =
        'mutation call($input: ProductSetInput!) { productSet(input: $input) ' .
        '{ product { id status handle } userErrors { field message } } }';

    /** GIDs of the products upserted by the most recent setMany() call (created + updated), harvested from the
     *  bulk-mutation results — so the caller can, e.g., publish freshly created products to sales channels. */
    protected array $arrLastUpsertedGids = [];

    /** Same upserted products as $arrLastUpsertedGids, but keyed by their (echoed) handle → GID, so a caller can
     *  correlate a FRESHLY CREATED product (whose GID it can't predict) back to the descriptor it sent, via the
     *  deterministic handle. */
    protected array $arrLastUpsertedGidsByHandle = [];


    /**
     * @param array $arrProducts list of generic single-variant product descriptors (see buildSetInput())
     * @return string[] per-row error messages (empty == every row applied cleanly)
     */
    public function setMany(array $arrProducts) : array
    {
        $this->arrLastUpsertedGids         = [];
        $this->arrLastUpsertedGidsByHandle = [];

        if( empty($arrProducts) ) {
            return [];
        }

        $locationGid  = $this->getPrimaryLocationGid();
        $arrVariables = array_map(
            fn($product) => ['input' => $this->buildSetInput($product, $locationGid)],
            array_values($arrProducts)
        );

        $arrLines                          = $this->run(static::SET_MUTATION, $arrVariables);
        $this->arrLastUpsertedGids         = $this->collectProductGids($arrLines);
        $this->arrLastUpsertedGidsByHandle = $this->collectProductGidsByHandle($arrLines);

        return $this->collectRowErrors($arrLines);
    }


    /** GIDs of the products upserted by the last setMany() call (created + updated). */
    public function getLastUpsertedGids() : array
    {
        return $this->arrLastUpsertedGids;
    }


    /**
     * [handle => GID] of the products upserted by the last setMany() — the correlation a caller needs to map a
     * just-created product back to the descriptor it sent (the flat getLastUpsertedGids() can't, as it drops
     * errored rows and so loses positional alignment). A product whose handle Shopify auto-suffixed on a collision
     * won't match the requested handle and is simply absent — the caller degrades gracefully on a miss.
     *
     * @return array<string,string>
     */
    public function getLastUpsertedGidsByHandle() : array
    {
        return $this->arrLastUpsertedGidsByHandle;
    }


    /**
     * Harvest the upserted product GIDs from the bulk-mutation result JSONL (same version-dependent shape probing
     * as collectRowErrors). Only rows that actually produced a product (no blocking userError) carry one.
     *
     * @return string[]
     */
    protected function collectProductGids(array $arrLines) : array
    {
        $arrGids = [];
        foreach($arrLines as $oLine) {

            $gid =
                $oLine->productSet->product->id
                ?? $oLine->data->productSet->product->id
                ?? $oLine->product->id
                ?? null;

            if( !empty($gid) ) {
                $arrGids[] = (string)$gid;
            }
        }

        return $arrGids;
    }


    /**
     * Harvest the upserted products as [handle => GID] from the bulk-mutation result JSONL (same version-dependent
     * shape probing as collectProductGids). Only rows that produced a product AND echoed a non-empty handle are
     * included.
     *
     * @return array<string,string>
     */
    protected function collectProductGidsByHandle(array $arrLines) : array
    {
        $arrByHandle = [];
        foreach($arrLines as $oLine) {

            $product =
                $oLine->productSet->product
                ?? $oLine->data->productSet->product
                ?? $oLine->product
                ?? null;

            $gid    = (string)($product->id     ?? '');
            $handle = (string)($product->handle ?? '');

            if( $gid !== '' && $handle !== '' ) {
                $arrByHandle[$handle] = $gid;
            }
        }

        return $arrByHandle;
    }


    /**
     * Map one generic product descriptor to a Shopify ProductSetInput. Product-level keys:
     *  - id            ?string   Shopify product GID (present → update; absent/null → create)
     *  - title          string
     *  - handle             ?string   the product slug. On CREATE: applied when present. On UPDATE: applied only
     *                                 when `redirectNewHandle` is also present (else Shopify keeps the live handle).
     *  - redirectNewHandle  ?bool     UPDATE opt-in. Present → re-assert `handle` on the existing product; true has
     *                                 Shopify 301-redirect the old storefront URL to the new one automatically.
     *  - vendor         string
     *  - productType    string
     *  - tags           string[] complete tag list (productSet REPLACES the list)
     *  - status        ?string   ACTIVE/DRAFT to set; null → leave unchanged
     *  - descriptionHtml ?string  native product body (HTML). A SCALAR ProductSetInput field, keyed on PRESENCE:
     *                            pass it to assert the body ('' clears it); omit the key to leave the current
     *                            body untouched (same convention as templateSuffix).
     *  - metafields     array[]  PRODUCT-level Shopify MetafieldInput rows (namespace/key/type/value)
     *  - files         ?array[]  product MEDIA as raw Shopify FileSetInput rows ({originalSource: <public url>}
     *                            or {id: <file gid>}, optional alt/contentType/...), in GALLERY ORDER. `files`
     *                            is a productSet LIST field (set semantics): when the KEY is present the
     *                            product's media becomes exactly this list (entries not included are detached;
     *                            an empty array clears everything); when ABSENT the media is left untouched.
     *                            A FileSetInput without `id` always creates+ingests a NEW file (duplicate
     *                            resolution is filename-based) — callers should include the key only when the
     *                            gallery actually changes, not on every run.
     *
     * Variants come EITHER as a named-option list or as the legacy single default-option variant:
     *  - optionName    ?string   name of the single product option (e.g. "Calibro"); null/'' → default Title option
     *  - variants       array[]  one row per variant, each: sku, price, available(int), allowBackorder(bool),
     *                            barcode(?string), metafields(array[]), optionValue(string — the option value,
     *                            ignored when optionName is null), file(?array — one FileSetInput row binding
     *                            the variant's image; use the SAME originalSource as the product `files` row
     *                            so Shopify associates that media instead of ingesting twice),
     *                            id(?string — the LIVE variant GID: when present, productSet updates THAT variant
     *                            in place — option value included — instead of matching rows by option values.
     *                            Without it, an option-value change is a delete+create, which breaks on variant
     *                            metafields with a uniqueValues constraint: the new variant's value collides with
     *                            the outgoing variant's before the latter is dropped. Omit on CREATE rows.)
     * When `variants` is absent the LEGACY single-variant shape is used instead: top-level sku/price/barcode/
     * available/allowBackorder + variantMetafields (+ file) → one default `Title`/`Default Title` variant.
     */
    protected function buildSetInput(array $product, string $locationGid) : array
    {
        // Legacy single-variant descriptor (top-level sku/price/...) → normalize into the `variants` list shape,
        // forcing the default Title option, so the rest of the method has a single code path.
        $arrVariantsDesc = $product['variants'] ?? null;
        $optionName      = (string)($product['optionName'] ?? '');

        if( $arrVariantsDesc === null ) {
            $arrVariantsDesc = [[
                'sku'               => $product['sku']             ?? '',
                'price'             => $product['price']           ?? '0',
                'barcode'           => $product['barcode']         ?? null,
                'available'         => $product['available']       ?? 0,
                'allowBackorder'    => $product['allowBackorder']  ?? false,
                'metafields'        => $product['variantMetafields'] ?? [],
                'file'              => $product['file']            ?? null,
            ]];
            $optionName = '';   // force the default Title option
        }

        $useDefaultOption = ($optionName === '');

        $arrVariants     = [];
        $arrOptionValues = [];   // distinct named-option values, for productOptions (unique per product)
        foreach($arrVariantsDesc as $varDesc) {

            if( $useDefaultOption ) {
                $arrOptValues = [['optionName' => static::DEFAULT_OPTION_NAME, 'name' => static::DEFAULT_OPTION_VALUE]];
            } else {
                $valueName         = (string)($varDesc['optionValue'] ?? '');
                $arrOptValues      = [['optionName' => $optionName, 'name' => $valueName]];
                $arrOptionValues[] = ['name' => $valueName];
            }

            $variant = [
                'optionValues'      => $arrOptValues,
                'price'             => (string)($varDesc['price'] ?? '0'),
                'inventoryPolicy'   => !empty($varDesc['allowBackorder']) ? 'CONTINUE' : 'DENY',
                'inventoryItem'     => [
                    'sku'       => (string)($varDesc['sku'] ?? ''),
                    'tracked'   => true,
                ],
                'inventoryQuantities' => [
                    [
                        'locationId'    => $locationGid,
                        'name'          => static::INVENTORY_STATE_AVAILABLE,
                        'quantity'      => (int)($varDesc['available'] ?? 0),
                    ],
                ],
            ];

            // live variant GID → identity-assert the match (see docblock); absent on CREATE rows
            if( !empty($varDesc['id']) ) {
                $variant['id'] = (string)$varDesc['id'];
            }

            if( !empty($varDesc['barcode']) ) {
                $variant['barcode'] = (string)$varDesc['barcode'];
            }

            if( !empty($varDesc['metafields']) ) {
                $variant['metafields'] = array_values($varDesc['metafields']);
            }

            if( !empty($varDesc['file']) ) {
                $variant['file'] = (array)$varDesc['file'];
            }

            $arrVariants[] = $variant;
        }

        $productOptions = $useDefaultOption
            ? [['name' => static::DEFAULT_OPTION_NAME, 'values' => [['name' => static::DEFAULT_OPTION_VALUE]]]]
            : [['name' => $optionName, 'values' => array_values($arrOptionValues)]];

        $input = [
            'title'             => (string)($product['title'] ?? ''),
            'vendor'            => (string)($product['vendor'] ?? ''),
            'productType'       => (string)($product['productType'] ?? ''),
            'tags'              => array_values($product['tags'] ?? []),
            'productOptions'    => $productOptions,
            'variants'          => $arrVariants,
            'metafields'        => array_values($product['metafields'] ?? []),
        ];

        // `files` is a LIST field with set semantics: key present = the media becomes exactly this list ([]
        // clears everything), key absent = media untouched → keyed on presence, NOT on empty()
        if( isset($product['files']) && is_array($product['files']) ) {
            $input['files'] = array_values($product['files']);
        }

        if( !empty($product['id']) ) {
            $input['id'] = (string)$product['id'];
        }

        // HANDLE. On CREATE: set the slug when provided. On UPDATE: Shopify keeps the existing handle by default
        // (protects the storefront URL); a caller opts into re-asserting it by passing `redirectNewHandle` in the
        // descriptor — we then set the handle AND let Shopify 301 the old URL to the new one (redirectNewHandle:true),
        // so no redirect bookkeeping is needed here. Callers that omit the key keep the create-only-handle behaviour.
        if( !empty($product['handle']) ) {
            if( empty($product['id']) ) {
                $input['handle'] = (string)$product['handle'];
            } elseif( array_key_exists('redirectNewHandle', $product) ) {
                $input['handle']            = (string)$product['handle'];
                $input['redirectNewHandle'] = (bool)$product['redirectNewHandle'];
            }
        }

        // omit status entirely when null so productSet leaves the current status untouched
        if( !empty($product['status']) ) {
            $input['status'] = (string)$product['status'];
        }

        // descriptionHtml (native product body) — SCALAR, keyed on PRESENCE like templateSuffix: a caller that
        // passes it asserts the body ('' clears it); callers that omit the key leave the current body untouched.
        if( array_key_exists('descriptionHtml', $product) ) {
            $input['descriptionHtml'] = (string)$product['descriptionHtml'];
        }

        // templateSuffix (native theme template) — a SCALAR ProductSetInput field, so keyed on PRESENCE: a caller
        // that passes it re-asserts the template ('' clears the suffix → the theme's default template); callers
        // that omit the key leave the current template untouched.
        if( array_key_exists('templateSuffix', $product) ) {
            $input['templateSuffix'] = (string)$product['templateSuffix'];
        }

        return $input;
    }


    /**
     * Bulk-mutation results JSONL: one object per executed productSet. Its top-level shape is version-dependent,
     * so probe the known candidate paths for per-row userErrors (mirrors ShopifyProductBulkStatusRequest).
     *
     * @return string[]
     */
    protected function collectRowErrors(array $arrLines) : array
    {
        $arrErrors = [];
        foreach($arrLines as $oLine) {

            $arrRowUserErrors =
                $oLine->userErrors
                ?? $oLine->data->productSet->userErrors
                ?? $oLine->productSet->userErrors
                ?? [];

            foreach($arrRowUserErrors as $oError) {
                $arrErrors[] = $this->formatUserError($oError);
            }

            $arrErrors = array_merge($arrErrors, $this->lineGraphqlErrors($oLine));
        }

        return $arrErrors;
    }
}
