<?php
namespace TurboLabIt\ShopifySdk\Request;

use TurboLabIt\ShopifySdk\Exception\ShopifyResponseException;


class ShopifyMetafieldDefinitionUpdateRequest extends ShopifyBaseAdminRequest
{
    protected string $templateFile = 'metafield-definition-update';

    /** Capabilities that, once enabled, must be re-asserted on every update — omitting them reads as "disable". */
    const array RECONCILABLE_CAPABILITIES = ['smartCollectionCondition', 'adminFilterable', 'uniqueValues'];


    public function update(array $arrDefinition) : \stdClass
    {
        // metafieldDefinitionUpdate treats an omitted capability as a request to DISABLE it. Some capabilities
        // can't be disabled (e.g. smartCollectionCondition — Shopify enables it by default on metaobject_reference
        // types and rejects the disable with CAPABILITY_CANNOT_BE_DISABLED). So we read the definition's currently
        // enabled capabilities and re-assert them, leaving their state untouched. See the capability probe findings.
        $arrDefinition['capabilities'] = $this->fetchEnabledCapabilities(
            $arrDefinition['ownerType'] ?? '',
            $arrDefinition['namespace'] ?? '',
            $arrDefinition['key'] ?? ''
        );

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


    /**
     * Read the live definition's capabilities and return the names of the ones currently enabled, so update()
     * can re-assert them. Returns [] when the definition isn't found yet or none are enabled (nothing to preserve).
     *
     * @return string[] subset of RECONCILABLE_CAPABILITIES that are currently enabled
     */
    protected function fetchEnabledCapabilities(string $ownerType, string $namespace, string $key) : array
    {
        if( $ownerType === '' || $namespace === '' || $key === '' ) {
            return [];
        }

        $query = sprintf(
            '{ metafieldDefinitions(first: 1, ownerType: %s, namespace: %s, key: %s) { nodes { capabilities { ' .
            'smartCollectionCondition { enabled } adminFilterable { enabled } uniqueValues { enabled } } } } }',
            $ownerType,                 // GraphQL enum — unquoted
            json_encode($namespace),
            json_encode($key)
        );

        $oResponse = $this->buildFromResponse(
            $this->setQuery($query, true)->connector->send($this)
        );

        $oCapabilities = $oResponse->data->metafieldDefinitions->nodes[0]->capabilities ?? null;
        if( $oCapabilities === null ) {
            return [];
        }

        $arrEnabled = [];
        foreach(static::RECONCILABLE_CAPABILITIES as $capability) {
            if( ($oCapabilities->{$capability}->enabled ?? false) === true ) {
                $arrEnabled[] = $capability;
            }
        }

        return $arrEnabled;
    }
}
