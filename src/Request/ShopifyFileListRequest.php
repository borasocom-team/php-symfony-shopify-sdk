<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Lists the shop's Files (paginated), optionally filtered with the file search syntax via `query` — e.g.
 * 'status:FAILED' for a post-import media health sweep: media ingestion (`files` originalSource URLs in
 * productSet / fileCreate) is asynchronous server-side, so an ingestion failure is invisible to the write
 * that submitted it and only surfaces on a later read like this one. A plain read — requires the
 * `read_files` access scope.
 *
 * Selects File-INTERFACE fields only (id, alt, createdAt, fileStatus, fileErrors), so every concrete file
 * type (MediaImage, Video, GenericFile, …) is covered; __typename tells them apart.
 *
 * ⚠️ The `query` filter is search-index-backed: under heavy concurrent writes it may briefly lag the live
 * data — fine for diagnostics/sweeps, not for strict-consistency reads.
 */
class ShopifyFileListRequest extends ShopifyBaseAdminRequest
{
    const int PER_PAGE = 250;


    /**
     * @param ?string $query file search syntax (e.g. 'status:FAILED'); null = every file on the store
     * @return \stdClass[] file nodes { id, __typename, alt, createdAt, fileStatus, fileErrors[{code, details, message}] }
     */
    public function getAll(?string $query = null) : array
    {
        $queryArg = $query === null ? '' : ', query: ' . json_encode($query);
        $arrOut   = [];
        $after    = null;

        do {
            $afterArg = $after === null ? '' : ', after: ' . json_encode($after);
            $gql      = sprintf(
                '{ files(first: %d%s%s) { pageInfo { hasNextPage endCursor } ' .
                'edges { node { id __typename alt createdAt fileStatus fileErrors { code details message } } } } }',
                static::PER_PAGE, $queryArg, $afterArg
            );

            $response  = $this->setQuery($gql, true)->connector->send($this);
            $oResponse = $this->buildFromResponse($response);
            $oFiles    = $oResponse->data->files ?? null;

            foreach($oFiles->edges ?? [] as $oEdge) {
                $arrOut[] = $oEdge->node;
            }

            $after = !empty($oFiles->pageInfo->hasNextPage) ? ($oFiles->pageInfo->endCursor ?? null) : null;

        } while($after !== null);

        return $arrOut;
    }
}
