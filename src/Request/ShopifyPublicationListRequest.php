<?php
namespace TurboLabIt\ShopifySdk\Request;


class ShopifyPublicationListRequest extends ShopifyBaseAdminRequest
{
    protected string $templateFile = 'publication-list';


    public function list() : array
    {
        $cacheKey = $this->buildCacheKey('list');
        if( array_key_exists($cacheKey, $this->arrCachedData) ) {
            return $this->arrCachedData[$cacheKey];
        }

        $response =
            $this
                ->setQueryFromTemplate([], null, true)
                ->connector->send($this);

        $oResponse  = $this->buildFromResponse($response);
        $arrEdges   = $oResponse->data->publications->edges ?? [];

        $arrAll = array_map(fn($edge) => $edge->node, $arrEdges);

        return $this->arrCachedData[$cacheKey] = $arrAll;
    }
}
