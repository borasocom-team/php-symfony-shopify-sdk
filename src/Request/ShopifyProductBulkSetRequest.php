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
        '{ product { id status } userErrors { field message } } }';

    protected ?string $primaryLocationGid = null;

    /** GIDs of the products upserted by the most recent setMany() call (created + updated), harvested from the
     *  bulk-mutation results — so the caller can, e.g., publish freshly created products to sales channels. */
    protected array $arrLastUpsertedGids = [];


    /**
     * @param array $arrProducts list of generic single-variant product descriptors (see buildSetInput())
     * @return string[] per-row error messages (empty == every row applied cleanly)
     */
    public function setMany(array $arrProducts) : array
    {
        $this->arrLastUpsertedGids = [];

        if( empty($arrProducts) ) {
            return [];
        }

        $locationGid  = $this->getPrimaryLocationGid();
        $arrVariables = array_map(
            fn($product) => ['input' => $this->buildSetInput($product, $locationGid)],
            array_values($arrProducts)
        );

        $arrLines                  = $this->run(static::SET_MUTATION, $arrVariables);
        $this->arrLastUpsertedGids = $this->collectProductGids($arrLines);

        return $this->collectRowErrors($arrLines);
    }


    /** GIDs of the products upserted by the last setMany() call (created + updated). */
    public function getLastUpsertedGids() : array
    {
        return $this->arrLastUpsertedGids;
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
     * Map one generic product descriptor to a Shopify ProductSetInput. Product-level keys:
     *  - id            ?string   Shopify product GID (present → update; absent/null → create)
     *  - title          string
     *  - handle        ?string   set on CREATE only (null on update → existing handle preserved)
     *  - vendor         string
     *  - productType    string
     *  - tags           string[] complete tag list (productSet REPLACES the list)
     *  - status        ?string   ACTIVE/DRAFT to set; null → leave unchanged
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
     *                            so Shopify associates that media instead of ingesting twice)
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

        // handle only on CREATE — never churn an existing product's handle (would 301 its storefront URL)
        if( empty($product['id']) && !empty($product['handle']) ) {
            $input['handle'] = (string)$product['handle'];
        }

        // omit status entirely when null so productSet leaves the current status untouched
        if( !empty($product['status']) ) {
            $input['status'] = (string)$product['status'];
        }

        return $input;
    }


    /**
     * Resolve (and cache) the GID of the location whose inventory we drive — the first location returned (the
     * connector targets a single-location store). Throws rather than silently skipping inventory.
     */
    public function getPrimaryLocationGid() : string
    {
        if( $this->primaryLocationGid !== null ) {
            return $this->primaryLocationGid;
        }

        $query     = 'query { locations(first: 1) { edges { node { id } } } }';
        $response  = $this->setQuery($query, true)->connector->send($this);
        $oResponse = $this->buildFromResponse($response);

        $gid = $oResponse->data->locations->edges[0]->node->id ?? null;
        if( empty($gid) ) {
            throw new \RuntimeException(static::class . ': could not resolve a primary Shopify location for inventory.');
        }

        return $this->primaryLocationGid = $gid;
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
                $arrErrors[] = ($oError->field ?? '') . ': ' . ($oError->message ?? '?');
            }
        }

        return $arrErrors;
    }
}
