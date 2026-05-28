<?php
namespace TurboLabIt\ShopifySdk\Request;

use TurboLabIt\ShopifySdk\Exception\ShopifyResponseException;


class ShopifyMetaobjectRequest extends ShopifyBaseAdminRequest
{
    protected string $templateFile = 'metaobjects-by-type';

    /** @var array<string, array{id:string, displayNameFieldKey:?string}> */
    private array $arrTypeDefinitions = [];


    public function getDefinition(string $type) : array
    {
        if( isset($this->arrTypeDefinitions[$type]) ) {
            return $this->arrTypeDefinitions[$type];
        }

        // Primary: metaobjectDefinitionByType. Direct, cheap, intended for this.
        $response =
            $this
                ->setQueryFromTemplate(['type' => $type], 'metaobject-definition-by-type', true)
                ->connector->send($this);

        $oResponse  = $this->buildFromResponse($response);
        $def        = $oResponse->data->metaobjectDefinitionByType ?? null;

        if( !empty($def->id) ) {
            return $this->arrTypeDefinitions[$type] = [
                'id'                  => $def->id,
                'displayNameFieldKey' => $def->displayNameKey ?? null,
            ];
        }

        // Fallback: some metaobject definitions are invisible to metaobjectDefinitionByType under certain
        // access-mode configurations, but their instances expose `definition { id displayNameKey }`. We read
        // the first instance and snapshot the definition from there. The fallback fails iff zero instances
        // exist — caller must bootstrap at least one instance manually in that case.
        $response =
            $this
                ->setQueryFromTemplate(['type' => $type], 'metaobject-definition-via-instance', true)
                ->connector->send($this);

        $oResponse = $this->buildFromResponse($response);
        $defEdge   = $oResponse->data->metaobjects->edges[0]->node->definition ?? null;

        if( empty($defEdge->id) ) {
            throw new ShopifyResponseException(
                "metaobject definition not found for type \"$type\" — neither metaobjectDefinitionByType nor instance-based fallback returned a definition. " .
                "If this type exists on the store, create at least one instance manually to seed the fallback."
            );
        }

        return $this->arrTypeDefinitions[$type] = [
            'id'                  => $defEdge->id,
            'displayNameFieldKey' => $defEdge->displayNameKey ?? null,
        ];
    }


    public function listByType(string $type) : array
    {
        $cacheKey = $this->buildCacheKey('listByType', $type);
        if( array_key_exists($cacheKey, $this->arrCachedData) ) {
            return $this->arrCachedData[$cacheKey];
        }

        $arrAll         = [];
        $afterCursor    = null;

        do {
            $response =
                $this
                    ->setQueryFromTemplate([
                        'type'        => $type,
                        'afterCursor' => $afterCursor,
                    ], 'metaobjects-by-type', true)
                    ->connector->send($this);

            $oResponse  = $this->buildFromResponse($response);
            $arrEdges   = $oResponse->data->metaobjects->edges ?? [];

            foreach($arrEdges as $edge) {
                $arrAll[] = $edge->node;
            }

            $hasNextPage    = $oResponse->data->metaobjects->pageInfo->hasNextPage ?? false;
            $afterCursor    = $oResponse->data->metaobjects->pageInfo->endCursor   ?? null;

        } while( $hasNextPage && $afterCursor );

        return $this->arrCachedData[$cacheKey] = $arrAll;
    }


    public function findByDisplayName(string $type, string $displayName) : ?\stdClass
    {
        foreach($this->listByType($type) as $mo) {
            if( ($mo->displayName ?? null) === $displayName ) {
                return $mo;
            }
        }
        return null;
    }


    public function findOrCreateByDisplayName(string $type, string $displayName) : string
    {
        $existing = $this->findByDisplayName($type, $displayName);
        if($existing !== null) {
            return $existing->id;
        }
        return $this->create($type, $displayName);
    }


    public function create(string $type, string $displayName) : string
    {
        $fieldKey = $this->resolveDisplayNameFieldKey($type);

        $response =
            $this
                ->setQueryFromTemplate([
                    'type'   => $type,
                    'fields' => [['key' => $fieldKey, 'value' => $displayName]],
                ], 'metaobject-create', true)
                ->connector->send($this);

        $oResponse      = $this->buildFromResponse($response);
        $arrUserErrors  = $oResponse->data->metaobjectCreate->userErrors ?? [];
        $this->throwOnUserErrors('metaobjectCreate', $arrUserErrors);

        $id = $oResponse->data->metaobjectCreate->metaobject->id ?? null;
        if(empty($id)) {
            throw new ShopifyResponseException('metaobjectCreate returned no id');
        }

        $this->bustListCache($type);
        return $id;
    }


    public function updateDisplayName(string $id, string $type, string $displayName) : void
    {
        $fieldKey = $this->resolveDisplayNameFieldKey($type);

        $response =
            $this
                ->setQueryFromTemplate([
                    'id'     => $id,
                    'fields' => [['key' => $fieldKey, 'value' => $displayName]],
                ], 'metaobject-update', true)
                ->connector->send($this);

        $oResponse      = $this->buildFromResponse($response);
        $arrUserErrors  = $oResponse->data->metaobjectUpdate->userErrors ?? [];
        $this->throwOnUserErrors('metaobjectUpdate', $arrUserErrors);

        $this->bustListCache($type);
    }


    public function delete(string $id) : void
    {
        $response =
            $this
                ->setQueryFromTemplate(['id' => $id], 'metaobject-delete', true)
                ->connector->send($this);

        $oResponse      = $this->buildFromResponse($response);
        $arrUserErrors  = $oResponse->data->metaobjectDelete->userErrors ?? [];
        $this->throwOnUserErrors('metaobjectDelete', $arrUserErrors);

        // we don't know the type, so blow every listByType cache
        foreach(array_keys($this->arrCachedData) as $k) {
            if( is_string($k) && str_starts_with($k, 'listByType.') ) {
                unset($this->arrCachedData[$k]);
            }
        }
    }


    private function resolveDisplayNameFieldKey(string $type) : string
    {
        $def      = $this->getDefinition($type);
        $fieldKey = $def['displayNameFieldKey'] ?? null;

        if(empty($fieldKey)) {
            throw new ShopifyResponseException("metaobject definition for type \"$type\" has no displayNameField");
        }

        return $fieldKey;
    }


    private function bustListCache(string $type) : void
    {
        unset($this->arrCachedData[$this->buildCacheKey('listByType', $type)]);
    }


    private function throwOnUserErrors(string $op, array $arrUserErrors) : void
    {
        if(empty($arrUserErrors)) {
            return;
        }
        $arrMessages = array_map(
            fn($oneError) => ($oneError->code ?? '?') . ': ' . ($oneError->message ?? '?'),
            $arrUserErrors
        );
        throw new ShopifyResponseException("$op userErrors: " . implode('; ', $arrMessages));
    }
}
