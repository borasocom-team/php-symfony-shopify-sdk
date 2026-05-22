<?php

namespace TurboLabIt\ShopifySdk\DataAdapter;

class DataAdapter
{
    public function shortShopifyId(\stdClass $responseObject) : string
    {
        $id = (string)($responseObject->id ?? '');

        return (string)preg_replace('#^gid://shopify/[^/]+/#i', '', $id);
    }


    public function isPublishedAnywhere(\stdClass $publishableNode) : bool
    {
        foreach($publishableNode->resourcePublications->edges ?? [] as $edge) {
            if( ($edge->node->isPublished ?? false) === true ) {
                return true;
            }
        }
        return false;
    }


    public function isFullyPublished(\stdClass $publishableNode, array $arrAllPublications) : bool
    {
        if( empty($arrAllPublications) ) {
            return true;
        }

        $arrNodePubs = [];
        foreach($publishableNode->resourcePublications->edges ?? [] as $edge) {

            if( ($edge->node->isPublished ?? false) === true ) {
                $arrNodePubs[$edge->node->publication->id ?? ''] = true;
            }
        }

        foreach($arrAllPublications as $pub) {
            if( !isset($arrNodePubs[$pub->id]) ) {
                return false;
            }
        }

        return true;
    }
}
