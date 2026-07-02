<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Bulk-sets absolute on-hand `available` quantities via Shopify's `inventorySetQuantities`, built on top of the
 * generic ShopifyBulkMutationRequest. Sibling of ShopifyProductBulkSetRequest, but surgical: it touches ONLY
 * inventory (one inventoryItem → one location → one absolute quantity), leaving every other product/variant
 * field untouched. This is the delta sync's stock-write primitive — it never re-declares the whole product.
 *
 * `ignoreCompareQuantity: true` makes the write unconditional (no optimistic-concurrency compareQuantity): the
 * caller already holds the source-of-truth quantity and wants it asserted, last-writer-wins.
 */
class ShopifyInventorySetRequest extends ShopifyBulkMutationRequest
{
    const string INVENTORY_STATE_AVAILABLE = 'available';
    const string SET_REASON                = 'correction';

    /** inventorySetQuantities accepts at most 250 quantities per input → one bulk row per chunk of this size. */
    const int MAX_QUANTITIES_PER_ROW = 250;

    const string SET_MUTATION =
        'mutation call($input: InventorySetQuantitiesInput!) { inventorySetQuantities(input: $input) ' .
        '{ inventoryAdjustmentGroup { createdAt } userErrors { field message } } }';


    /**
     * @param array $arrItemQuantities one row per inventoryItem: ['inventoryItemId' => <gid>, 'quantity' => <int>]
     * @return string[] per-row error messages (empty == every quantity set cleanly)
     */
    public function setMany(array $arrItemQuantities) : array
    {
        $arrItemQuantities = array_values(array_filter(
            $arrItemQuantities,
            fn($row) => !empty($row['inventoryItemId'])
        ));
        if( empty($arrItemQuantities) ) {
            return [];
        }

        $locationGid = $this->getPrimaryLocationGid();

        // pack into ≤250-quantity rows; each row is one inventorySetQuantities call inside the bulk operation
        $arrVariables = array_map(
            fn(array $arrChunk) => ['input' => [
                'name'                  => static::INVENTORY_STATE_AVAILABLE,
                'reason'                => static::SET_REASON,
                'ignoreCompareQuantity' => true,
                'quantities'            => array_map(
                    fn($row) => [
                        'inventoryItemId'   => (string)$row['inventoryItemId'],
                        'locationId'        => $locationGid,
                        'quantity'          => (int)($row['quantity'] ?? 0),
                    ],
                    $arrChunk
                ),
            ]],
            array_chunk($arrItemQuantities, static::MAX_QUANTITIES_PER_ROW)
        );

        return $this->collectRowErrors($this->run(static::SET_MUTATION, $arrVariables));
    }


    /** @return string[] */
    protected function collectRowErrors(array $arrLines) : array
    {
        $arrErrors = [];
        foreach($arrLines as $oLine) {

            $arrRowUserErrors =
                $oLine->userErrors
                ?? $oLine->data->inventorySetQuantities->userErrors
                ?? $oLine->inventorySetQuantities->userErrors
                ?? [];

            foreach($arrRowUserErrors as $oError) {
                $arrErrors[] = $this->formatUserError($oError);
            }
        }

        return $arrErrors;
    }
}
