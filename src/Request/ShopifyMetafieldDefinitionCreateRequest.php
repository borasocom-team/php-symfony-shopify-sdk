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


    /**
     * The live type name of an existing definition (e.g. "single_line_text_field", "number_integer"), or null
     * if it doesn't exist. create() reports an existing definition only as TAKEN (no type), and the type is
     * immutable in Shopify, so callers use this to detect/refuse a pre-existing definition of the wrong type.
     */
    public function getDefinitionType(string $ownerType, string $namespace, string $key) : ?string
    {
        $query = sprintf(
            'query { metafieldDefinitions(first: 1, ownerType: %s, namespace: %s, key: %s) { edges { node { type { name } } } } }',
            $ownerType,                                 // GraphQL enum → unquoted
            json_encode($namespace, JSON_THROW_ON_ERROR),
            json_encode($key, JSON_THROW_ON_ERROR)
        );

        $response  = $this->setQuery($query, true)->connector->send($this);
        $oResponse = $this->buildFromResponse($response);

        return $oResponse->data->metafieldDefinitions->edges[0]->node->type->name ?? null;
    }
}
