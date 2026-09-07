<?php
namespace TurboLabIt\ShopifySdk\Request;

use TurboLabIt\ShopifySdk\Exception\ShopifyResponseException;


class ShopifySmartCollectionUpdateRequest extends ShopifyBaseAdminRequest
{
    protected string $templateFile = 'smart-collection-update';


    /**
     * collectionUpdate. $arrInput keys: id, title, ruleSet? (same shape as create), templateSuffix? — the native
     * theme template, a SCALAR keyed on PRESENCE: pass it to re-assert the template ('' clears the suffix → theme
     * default), omit the key to leave the current template untouched. Returns the updated collection node (id,
     * title, handle, templateSuffix, ruleSet); throws ShopifyResponseException on userErrors.
     */
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
                fn($oneError) => $this->formatUserError($oneError),
                $arrUserErrors
            );
            throw new ShopifyResponseException('collectionUpdate userErrors: ' . implode('; ', $arrMessages));
        }

        return $oResponse->data->collectionUpdate->collection;
    }
}
