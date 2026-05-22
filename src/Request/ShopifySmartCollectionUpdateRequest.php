<?php
namespace TurboLabIt\ShopifySdk\Request;

use TurboLabIt\ShopifySdk\Exception\ShopifyResponseException;


class ShopifySmartCollectionUpdateRequest extends ShopifyBaseAdminRequest
{
    protected string $templateFile = 'smart-collection-update';


    public function update(array $arrInput) : \stdClass
    {
        $response =
            $this
                ->setQueryFromTemplate($arrInput, null, true)
                ->connector->send($this);

        $oResponse      = $this->buildFromResponse($response);
        $arrUserErrors  = $oResponse->data->collectionUpdate->userErrors ?? [];

        if( !empty($arrUserErrors) ) {

            $arrMessages = array_map(
                fn($oneError) => ($oneError->field ?? '') . ': ' . ($oneError->message ?? '?'),
                $arrUserErrors
            );
            throw new ShopifyResponseException('collectionUpdate userErrors: ' . implode('; ', $arrMessages));
        }

        return $oResponse->data->collectionUpdate->collection;
    }
}
