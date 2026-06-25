<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Sets product `status` in bulk (DRAFT to hide, ACTIVE to revive). Thin specialisation of
 * ShopifyProductBulkUpdateRequest: a status change is just a `productUpdate` patching the single `status`
 * field, so the mutation, the bulk plumbing and the per-row error harvesting are all inherited.
 */
class ShopifyProductBulkStatusRequest extends ShopifyProductBulkUpdateRequest
{
    const string STATUS_DRAFT  = 'DRAFT';
    const string STATUS_ACTIVE = 'ACTIVE';


    /** @return string[] per-row error messages (empty array == every product updated) */
    public function draft(array $arrProductGids) : array     { return $this->setStatus($arrProductGids, static::STATUS_DRAFT); }

    /** @return string[] per-row error messages (empty array == every product updated) */
    public function activate(array $arrProductGids) : array   { return $this->setStatus($arrProductGids, static::STATUS_ACTIVE); }


    /** @return string[] per-row error messages (empty array == every product updated) */
    public function setStatus(array $arrProductGids, string $status) : array
    {
        $arrProductGids = array_values(array_unique(array_filter($arrProductGids)));

        return $this->updateMany(array_map(
            fn($gid) => ['id' => $gid, 'status' => $status],
            $arrProductGids
        ));
    }
}
