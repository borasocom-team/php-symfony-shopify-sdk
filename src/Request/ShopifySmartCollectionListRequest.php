<?php
namespace TurboLabIt\ShopifySdk\Request;


class ShopifySmartCollectionListRequest extends ShopifyBaseAdminRequest
{
    const int MAX_PAGES         = 1000;
    const array DEFAULT_FILTERS = [
        'collection_type'   => 'smart',
        'published_status'  => 'any',
    ];

    protected string $templateFile = 'smart-collections';


    public function listVisible(array $arrFilters = []) : array
    {
        // hard-lock `published_status:published` so callers cannot subvert the "visible" guarantee:
        // PHP `+` keeps the LEFT side on key collision.
        return $this->list(['published_status' => 'published'] + $arrFilters);
    }


    public function list(array $arrFilters = []) : array
    {
        $arrFilters     = $arrFilters + static::DEFAULT_FILTERS;

        $cacheKey = $this->buildCacheKey('list', $arrFilters);
        if( array_key_exists($cacheKey, $this->arrCachedData) ) {
            return $this->arrCachedData[$cacheKey];
        }

        $shopifyQuery   = $this->buildShopifyQuery($arrFilters);
        $arrAll         = [];
        $afterCursor    = null;
        $pageNum        = 0;

        do {

            if( ++$pageNum > static::MAX_PAGES ) {
                throw new \RuntimeException(sprintf(
                    'ShopifySmartCollectionListRequest: pagination exceeded %d pages (query: "%s"). Aborting to avoid runaway loop.',
                    static::MAX_PAGES, $shopifyQuery
                ));
            }

            $response =
                $this
                    ->setQueryFromTemplate([
                        'shopifyQuery'  => $shopifyQuery,
                        'afterCursor'   => $afterCursor,
                    ], null, true)
                    ->connector->send($this);

            $oResponse  = $this->buildFromResponse($response);
            $arrEdges   = $oResponse->data->collections->edges ?? [];

            foreach($arrEdges as $edge) {
                $arrAll[] = $edge->node;
            }

            $hasNextPage    = $oResponse->data->collections->pageInfo->hasNextPage   ?? false;
            $afterCursor    = $oResponse->data->collections->pageInfo->endCursor     ?? null;

        } while( $hasNextPage && $afterCursor );

        return $this->arrCachedData[$cacheKey] = $arrAll;
    }


    protected function buildShopifyQuery(array $arrFilters) : string
    {
        $arrParts = [];
        foreach($arrFilters as $field => $value) {

            if( $value === null || $value === '' ) {
                continue;
            }

            $arrParts[] = $field . ':' . $value;
        }

        return implode(' ', $arrParts);
    }
}
