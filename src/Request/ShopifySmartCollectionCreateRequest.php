<?php
namespace TurboLabIt\ShopifySdk\Request;

use TurboLabIt\ShopifySdk\Exception\ShopifyResponseException;


class ShopifySmartCollectionCreateRequest extends ShopifyBaseAdminRequest
{
    protected string $templateFile = 'smart-collection-create';


    /**
     * collectionCreate. $arrInput keys: title, handle, ruleSet? {appliedDisjunctively, rules[{column, relation,
     * condition, conditionObjectId?}]}, metafields? [{namespace, key, type, value}], templateSuffix? — the native
     * theme template, a SCALAR keyed on PRESENCE: pass it to assert the template ('' = the theme's default), omit the
     * key to let Shopify apply the default. Returns the created collection node (id, title, handle, templateSuffix,
     * ruleSet); throws ShopifyResponseException on userErrors.
     */
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
