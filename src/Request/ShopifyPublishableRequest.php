<?php
namespace TurboLabIt\ShopifySdk\Request;

use Twig\Environment;
use TurboLabIt\ShopifySdk\Connector\ShopifyAdminConnector;
use TurboLabIt\ShopifySdk\Exception\ShopifyResponseException;


class ShopifyPublishableRequest extends ShopifyBaseAdminRequest
{
    protected string $templateFile = 'publishable-publish';


    public function __construct(
        array $arrConfig,
        Environment $twig,
        ShopifyAdminConnector $connector,
        protected ShopifyPublicationListRequest $shopifyPublications,
    )
    {
        parent::__construct($arrConfig, $twig, $connector);
    }


    public function publish(string $resourceGid, array $arrPublicationIds) : \stdClass
    {
        if( empty($arrPublicationIds) ) {
            throw new \InvalidArgumentException('publish() requires at least one publication id');
        }

        $response =
            $this
                ->setQueryFromTemplate([
                    'resourceGid'       => $resourceGid,
                    'publicationIds'    => $arrPublicationIds,
                ], null, true)
                ->connector->send($this);

        $oResponse      = $this->buildFromResponse($response);
        $arrUserErrors  = $oResponse->data->publishablePublish->userErrors ?? [];

        if( !empty($arrUserErrors) ) {

            $arrMessages = array_map(
                fn($oneError) => ($oneError->field ?? '') . ': ' . ($oneError->message ?? '?'),
                $arrUserErrors
            );
            throw new ShopifyResponseException('publishablePublish userErrors: ' . implode('; ', $arrMessages));
        }

        return $oResponse->data->publishablePublish->publishable ?? new \stdClass();
    }


    public function unpublish(string $resourceGid, array $arrPublicationIds) : \stdClass
    {
        if( empty($arrPublicationIds) ) {
            throw new \InvalidArgumentException('unpublish() requires at least one publication id');
        }

        $response =
            $this
                ->setQueryFromTemplate([
                    'resourceGid'       => $resourceGid,
                    'publicationIds'    => $arrPublicationIds,
                ], 'publishable-unpublish', true)
                ->connector->send($this);

        $oResponse      = $this->buildFromResponse($response);
        $arrUserErrors  = $oResponse->data->publishableUnpublish->userErrors ?? [];

        if( !empty($arrUserErrors) ) {

            $arrMessages = array_map(
                fn($oneError) => ($oneError->field ?? '') . ': ' . ($oneError->message ?? '?'),
                $arrUserErrors
            );
            throw new ShopifyResponseException('publishableUnpublish userErrors: ' . implode('; ', $arrMessages));
        }

        return $oResponse->data->publishableUnpublish->publishable ?? new \stdClass();
    }


    public function publishToAllChannels(string $resourceGid) : \stdClass
    {
        return $this->publish($resourceGid, $this->allPublicationIds());
    }


    public function unpublishFromAllChannels(string $resourceGid) : \stdClass
    {
        return $this->unpublish($resourceGid, $this->allPublicationIds());
    }


    /** Every sales-channel publication id on the store (cached by the underlying list request). Public so callers
     *  can publish many resources to the same id set without re-fetching, and know the total channel count. */
    public function getAllPublicationIds() : array
    {
        return $this->allPublicationIds();
    }


    protected function allPublicationIds() : array
    {
        return array_map(fn($pub) => $pub->id, $this->shopifyPublications->list());
    }
}
