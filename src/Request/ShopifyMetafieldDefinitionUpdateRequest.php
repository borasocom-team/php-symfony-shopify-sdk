<?php
namespace TurboLabIt\ShopifySdk\Request;

use TurboLabIt\ShopifySdk\Exception\ShopifyResponseException;


class ShopifyMetafieldDefinitionUpdateRequest extends ShopifyBaseAdminRequest
{
    protected string $templateFile = 'metafield-definition-update';


    public function update(array $arrDefinition) : \stdClass
    {
        $response =
            $this
                ->setQueryFromTemplate($arrDefinition, null, true)
                ->connector->send($this);

        $oResponse      = $this->buildFromResponse($response);
        $arrUserErrors  = $oResponse->data->metafieldDefinitionUpdate->userErrors ?? [];

        if( !empty($arrUserErrors) ) {

            $arrMessages = array_map(
                fn($oneError) => ($oneError->code ?? '?') . ': ' . ($oneError->message ?? '?'),
                $arrUserErrors
            );
            throw new ShopifyResponseException('metafieldDefinitionUpdate userErrors: ' . implode('; ', $arrMessages));
        }

        return $oResponse->data->metafieldDefinitionUpdate->updatedDefinition ?? new \stdClass();
    }
}
