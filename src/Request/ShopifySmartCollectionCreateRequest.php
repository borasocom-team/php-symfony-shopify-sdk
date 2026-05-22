<?php
namespace TurboLabIt\ShopifySdk\Request;

use TurboLabIt\ShopifySdk\Exception\ShopifyResponseException;


class ShopifySmartCollectionCreateRequest extends ShopifyBaseAdminRequest
{
    protected string $templateFile = 'smart-collection-create';


    public function create(array $arrInput) : \stdClass
    {
        $response =
            $this
                ->setQueryFromTemplate($arrInput, null, true)
                ->connector->send($this);

        $oResponse      = $this->buildFromResponse($response);
        $arrUserErrors  = $oResponse->data->collectionCreate->userErrors ?? [];

        if( !empty($arrUserErrors) ) {

            $arrMessages = array_map(
                fn($oneError) => ($oneError->code ?? '?') . ': ' . ($oneError->message ?? '?'),
                $arrUserErrors
            );
            throw new ShopifyResponseException('collectionCreate userErrors: ' . implode('; ', $arrMessages));
        }

        return $oResponse->data->collectionCreate->collection;
    }
}
