<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Bulk-sets absolute on-hand `available` quantities via Shopify's `inventorySetQuantities`, built on top of the
 * generic ShopifyBulkMutationRequest. Sibling of ShopifyProductBulkSetRequest, but surgical: it touches ONLY
 * inventory (one inventoryItem → one location → one absolute quantity), leaving every other product/variant
 * field untouched. This is the delta sync's stock-write primitive — it never re-declares the whole product.
 *
 * The write is unconditional (no optimistic-concurrency compare): each quantity row carries an EXPLICIT
 * `changeFromQuantity: null` — since API 2026-04 that is the only way to express it (the former
 * `ignoreCompareQuantity` flag no longer exists on InventorySetQuantitiesInput, and omitting the key is
 * rejected with "must include the following argument: changeFromQuantity"). The caller already holds the
 * source-of-truth quantity and wants it asserted, last-writer-wins.
 */
class ShopifyInventorySetRequest extends ShopifyBulkMutationRequest
{
    const string INVENTORY_STATE_AVAILABLE = 'available';
    const string SET_REASON                = 'correction';

    /** inventorySetQuantities accepts at most 250 quantities per input → one bulk row per chunk of this size. */
    const int MAX_QUANTITIES_PER_ROW = 250;

    /** API 2026-04 requires the @idempotent directive on inventorySetQuantities; each bulk row carries its own
     *  fresh UUID via $idemKey, so rows are never mistaken for replays of each other. */
    const string SET_MUTATION =
        'mutation call($input: InventorySetQuantitiesInput!, $idemKey: String!) ' .
        '{ inventorySetQuantities(input: $input) @idempotent(key: $idemKey) ' .
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
            fn(array $arrChunk) => ['idemKey' => $this->newIdempotencyKey(), 'input' => [
                'name'                  => static::INVENTORY_STATE_AVAILABLE,
                'reason'                => static::SET_REASON,
                'quantities'            => array_map(
                    fn($row) => [
                        'inventoryItemId'    => (string)$row['inventoryItemId'],
                        'locationId'         => $locationGid,
                        'quantity'           => (int)($row['quantity'] ?? 0),
                        'changeFromQuantity' => null,
                    ],
                    $arrChunk
                ),
            ]],
            array_chunk($arrItemQuantities, static::MAX_QUANTITIES_PER_ROW)
        );

        return $this->collectRowErrors($this->run(static::SET_MUTATION, $arrVariables));
    }


    /** A fresh RFC-4122 v4 UUID per mutation row (the @idempotent key — see SET_MUTATION). */
    protected function newIdempotencyKey() : string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
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

            // a row can also fail at the GraphQL level INSIDE the bulk op (top-level `errors` on the result
            // line, data null, NO userErrors) — e.g. an input-shape rejection. Silent before this: the write
            // no-opped while the run reported success.
            foreach($oLine->errors ?? [] as $oError) {
                $arrErrors[] = (string)($oError->message ?? json_encode($oError));
            }
        }

        return $arrErrors;
    }
}
