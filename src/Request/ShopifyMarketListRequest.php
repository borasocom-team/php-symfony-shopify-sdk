<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Lists the shop's Markets (paginated, id + name). A plain read — requires the `read_markets` access scope.
 */
class ShopifyMarketListRequest extends ShopifyBaseAdminRequest
{
    const int PER_PAGE = 50;


    /**
     * @param array $arrMetafields each ['alias' => , 'namespace' => , 'key' => ]: every node then carries
     *              ->{alias} { value } for that metafield (the whole ->{alias} is null when the market has no
     *              value set yet). Aliases are inlined as GraphQL field aliases — pass safe identifiers (the
     *              caller's own constants), not user input.
     * @return \stdClass[] market nodes { id, name[, <alias> { value } … ] }
     */
    public function getAll(array $arrMetafields = []) : array
    {
        $metafieldFields = '';
        foreach($arrMetafields as $mf) {
            $metafieldFields .= sprintf(
                ' %s: metafield(namespace: %s, key: %s) { value }',
                $mf['alias'],
                json_encode($mf['namespace'], JSON_THROW_ON_ERROR),
                json_encode($mf['key'], JSON_THROW_ON_ERROR)
            );
        }

        $arrMarkets = [];
        $after      = null;

        do {
            $afterArg = $after === null ? '' : ', after: ' . json_encode($after);
            $query    = sprintf(
                '{ markets(first: %d%s) { pageInfo { hasNextPage endCursor } edges { node { id name%s } } } }',
                static::PER_PAGE, $afterArg, $metafieldFields
            );

            $response  = $this->setQuery($query, true)->connector->send($this);
            $oResponse = $this->buildFromResponse($response);
            $oMarkets  = $oResponse->data->markets ?? null;

            foreach($oMarkets->edges ?? [] as $oEdge) {
                $arrMarkets[] = $oEdge->node;
            }

            $after = !empty($oMarkets->pageInfo->hasNextPage) ? ($oMarkets->pageInfo->endCursor ?? null) : null;

        } while($after !== null);

        return $arrMarkets;
    }
}
