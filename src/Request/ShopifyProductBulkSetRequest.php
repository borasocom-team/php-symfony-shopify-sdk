<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Bulk create+update (upsert) of products via Shopify's `productSet` mutation, run as a single async bulk
 * operation (staged JSONL → bulkOperationRunMutation → poll by GID → per-row results, all inherited from
 * ShopifyBulkMutationRequest). Sibling of ShopifyProductBulkStatusRequest: that one bulk-sets `status`, this
 * one bulk-sets the whole product.
 *
 * `productSet` upserts: a row WITH `id` updates, a row WITHOUT creates. setMany() takes generic single-variant
 * product descriptors (see buildSetInput()) — title, vendor, productType, tags, status, a single variant with
 * SKU/price/barcode/inventory, and arbitrary metafields — and applies them all in one bulk op. It carries no
 * domain knowledge (e.g. which metafields mean what): the caller supplies the descriptors.
 *
 * ⚠️ The exact ProductVariantSetInput shape (SKU under `inventoryItem`, `inventoryQuantities` at a location,
 * the reserved `Title`/`Default Title` default option) is API-version sensitive and was written without live
 * schema validation. It is assembled in ONE place (buildSetInput) so a first staging run / validate_graphql
 * can pin any field drift in a single edit.
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


    /**
     * @param array $arrProducts list of generic single-variant product descriptors (see buildSetInput())
     * @return string[] per-row error messages (empty == every row applied cleanly)
     */
    public function setMany(array $arrProducts) : array
    {
        if( empty($arrProducts) ) {
            return [];
        }

        $locationGid  = $this->getPrimaryLocationGid();
        $arrVariables = array_map(
            fn($product) => ['input' => $this->buildSetInput($product, $locationGid)],
            array_values($arrProducts)
        );

        return $this->collectRowErrors($this->run(static::SET_MUTATION, $arrVariables));
    }


    /**
     * Map one generic single-variant product descriptor to a Shopify ProductSetInput. Expected $product keys:
     *  - id            ?string   Shopify product GID (present → update; absent/null → create)
     *  - sku            string   variant SKU (set on the variant's inventoryItem)
     *  - title          string
     *  - handle        ?string   set on CREATE only (null on update → existing handle preserved)
     *  - vendor         string
     *  - productType    string
     *  - tags           string[] complete tag list (productSet REPLACES the list)
     *  - status        ?string   ACTIVE/DRAFT to set; null → leave unchanged
     *  - price          string   variant price, e.g. "147.00"
     *  - barcode       ?string   variant barcode (omitted when empty)
     *  - available      int      stock to set at the primary location
     *  - allowBackorder bool     CONTINUE vs DENY when out of stock
     *  - metafields     array[]  Shopify MetafieldInput rows (namespace/key/type/value) — caller-supplied
     */
    protected function buildSetInput(array $product, string $locationGid) : array
    {
        $sku = (string)($product['sku'] ?? '');

        $variant = [
            'optionValues'  => [
                ['optionName' => static::DEFAULT_OPTION_NAME, 'name' => static::DEFAULT_OPTION_VALUE],
            ],
            'price'             => (string)($product['price'] ?? '0'),
            'inventoryPolicy'   => !empty($product['allowBackorder']) ? 'CONTINUE' : 'DENY',
            'inventoryItem'     => [
                'sku'       => $sku,
                'tracked'   => true,
            ],
            'inventoryQuantities' => [
                [
                    'locationId'    => $locationGid,
                    'name'          => static::INVENTORY_STATE_AVAILABLE,
                    'quantity'      => (int)($product['available'] ?? 0),
                ],
            ],
        ];

        if( !empty($product['barcode']) ) {
            $variant['barcode'] = (string)$product['barcode'];
        }

        $input = [
            'title'             => (string)($product['title'] ?? ''),
            'vendor'            => (string)($product['vendor'] ?? ''),
            'productType'       => (string)($product['productType'] ?? ''),
            'tags'              => array_values($product['tags'] ?? []),
            'productOptions'    => [
                ['name' => static::DEFAULT_OPTION_NAME, 'values' => [['name' => static::DEFAULT_OPTION_VALUE]]],
            ],
            'variants'          => [$variant],
            'metafields'        => array_values($product['metafields'] ?? []),
        ];

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
