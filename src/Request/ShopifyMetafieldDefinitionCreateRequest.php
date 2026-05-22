<?php
namespace TurboLabIt\ShopifySdk\Request;

use TurboLabIt\ShopifySdk\Exception\ShopifyResponseException;


class ShopifyMetafieldDefinitionCreateRequest extends ShopifyBaseAdminRequest
{
    const string ERROR_CODE_TAKEN = 'TAKEN';

    protected string $templateFile = 'metafield-definition-create';


    public function create(array $arrDefinition) : ?\stdClass
    {
        $response =
            $this
                ->setQueryFromTemplate($arrDefinition, null, true)
                ->connector->send($this);

        $oResponse      = $this->buildFromResponse($response);
        $arrUserErrors  = $oResponse->data->metafieldDefinitionCreate->userErrors ?? [];

        foreach($arrUserErrors as $oneError) {

            if( ($oneError->code ?? null) === static::ERROR_CODE_TAKEN ) {
                return null;
            }
        }

        if( !empty($arrUserErrors) ) {

            $arrMessages = array_map(
                fn($oneError) => ($oneError->code ?? '?') . ': ' . ($oneError->message ?? '?'),
                $arrUserErrors
            );
            throw new ShopifyResponseException('metafieldDefinitionCreate userErrors: ' . implode('; ', $arrMessages));
        }

        return $oResponse->data->metafieldDefinitionCreate->createdDefinition ?? null;
    }
}
