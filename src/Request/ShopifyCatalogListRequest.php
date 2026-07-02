<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Lists the shop's Catalogs (paginated: id + title + status + the backing publication id). A plain read — requires
 * the `read_publications` access scope. Optionally filtered to a single CatalogType via the `type` argument (e.g.
 * 'MARKET' for the per-market catalogs, 'APP' for the sales-channel catalogs, 'COMPANY_LOCATION' for B2B).
 *
 * A product is a member of a catalog iff it is published to that catalog's publication, so the returned
 * `publication.id` is the id to feed ShopifyPublishableRequest::publish()/unpublish().
 */
class ShopifyCatalogListRequest extends ShopifyBaseAdminRequest
{
    const int PER_PAGE = 50;

    /** The CatalogType enum values accepted by the `type` filter. Inlined UNQUOTED into the query (GraphQL enums),
     *  so the caller's value is allowlisted here — it can never inject arbitrary text into the query string. */
    const array CATALOG_TYPES = ['APP', 'COMPANY_LOCATION', 'MARKET'];


    /**
     * @param ?string $type one of CATALOG_TYPES (null = every type). Validated against the allowlist, then inlined
     *                      as an unquoted GraphQL enum.
     * @return \stdClass[] catalog nodes { id, title, status, publication { id } }
     */
    public function getAll(?string $type = null) : array
    {
        if( $type !== null && !in_array($type, static::CATALOG_TYPES, true) ) {
            throw new \InvalidArgumentException('Unknown catalog type: ' . $type);
        }

        $typeArg   = $type === null ? '' : ', type: ' . $type;
        $arrOut    = [];
        $after     = null;

        do {
            $afterArg = $after === null ? '' : ', after: ' . json_encode($after);
            $query    = sprintf(
                '{ catalogs(first: %d%s%s) { pageInfo { hasNextPage endCursor } edges { node { id title status publication { id } } } } }',
                static::PER_PAGE, $typeArg, $afterArg
            );

            $response  = $this->setQuery($query, true)->connector->send($this);
            $oResponse = $this->buildFromResponse($response);
            $oCatalogs = $oResponse->data->catalogs ?? null;

            foreach($oCatalogs->edges ?? [] as $oEdge) {
                $arrOut[] = $oEdge->node;
            }

            $after = !empty($oCatalogs->pageInfo->hasNextPage) ? ($oCatalogs->pageInfo->endCursor ?? null) : null;

        } while($after !== null);

        return $arrOut;
    }
}
