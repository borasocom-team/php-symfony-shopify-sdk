<?php
namespace TurboLabIt\ShopifySdk\Request;

use TurboLabIt\ShopifySdk\Exception\ShopifyResponseException;


class ShopifyMetaobjectRequest extends ShopifyBaseAdminRequest
{
    protected string $templateFile = 'metaobjects-by-type';

    /** @var array<string, array{id:string, displayNameFieldKey:?string, fields:?array<string,string>, translatable:?bool}>
     *       $fields = [key => name]; `fields`/`translatable` are null when un-introspectable (instance fallback) */
    private array $arrTypeDefinitions = [];

    /** Field keys reconciled (added OR renamed) the last time ensureDefinition() touched an existing definition. */
    private array $arrLastReconciledFieldKeys = [];


    /**
     * Ensure a metaobject definition of $type exists (idempotent): create it with the given fields when absent,
     * else reuse the existing one AND reconcile field drift — adds any field in $fieldDefinitions missing on the
     * store (so a newly-declared field takes effect) and renames any existing field whose label (name) changed
     * (key/type are immutable). Returns the definition GID. $displayNameKey must be one of the field keys (used as
     * the entry's display name, which findOrCreateByDisplayName() looks up by).
     *
     * $translatable asserts the definition-level TRANSLATABLE capability — the switch that makes every entry's
     * text fields translatable content (Translations API / the merchant's Translate & Adapt), keyed by the field
     * key; there is no per-field flag. It is asserted ONE WAY, like every capability reconcile here: `true`
     * switches it on when the store has it off, `false` (the default) leaves whatever the store has alone —
     * turning it off would drop existing translations, which no reconcile should ever do implicitly.
     *
     * @param array<int,array{key:string,name:string,type:string}> $fieldDefinitions
     */
    public function ensureDefinition(
        string $type, string $name, array $fieldDefinitions, string $displayNameKey = 'label', bool $translatable = false
    ) : string
    {
        $this->arrLastReconciledFieldKeys = [];

        try {
            $def = $this->getDefinition($type);
        } catch (ShopifyResponseException) {
            return $this->createDefinition($type, $name, $fieldDefinitions, $displayNameKey, $translatable);
        }

        $this->reconcileDefinition($type, $def, $fieldDefinitions, $translatable);

        return $def['id'];
    }


    /**
     * Field keys reconciled (added or renamed) by the most recent ensureDefinition() call on an existing
     * definition. Empty when the definition was freshly created or already matched the desired fields.
     *
     * @return array<int,string>
     */
    public function getLastReconciledFieldKeys() : array
    {
        return $this->arrLastReconciledFieldKeys;
    }


    /**
     * Reconcile an existing metaobject definition against $fieldDefinitions: ADD any field whose key is missing,
     * and RENAME (update `name`) any existing field whose label drifted. key/type are immutable, so renames carry
     * only key + name. No-op when nothing drifted, or when the field set couldn't be introspected (instance-based
     * fallback). Never deletes: a field on the store but absent from $fieldDefinitions is left untouched.
     *
     * $translatable=true additionally switches the definition's translatable capability on when the store has it
     * off (never off — see ensureDefinition). Both halves ride ONE metaobjectDefinitionUpdate; a capability-only
     * flip is reconciled even when the field set is un-introspectable.
     *
     * @param array{id:string, displayNameFieldKey:?string, fields:?array<string,string>, translatable:?bool} $def
     * @param array<int,array{key:string,name:string,type:string}> $fieldDefinitions
     */
    private function reconcileDefinition(string $type, array $def, array $fieldDefinitions, bool $translatable = false) : void
    {
        // capability drift is independent of the field set: assert it even on the instance-based fallback (whose
        // `translatable` is null = unknown → nothing to compare against, so leave it alone)
        $enableTranslatable = $translatable && ($def['translatable'] ?? null) === false;

        $arrCreates = [];   // key not on the store yet → add it
        $arrUpdates = [];   // key present but its label (name) changed → rename it

        $arrExistingFields = $def['fields'] ?? null;
        if($arrExistingFields !== null) {
            foreach($fieldDefinitions as $fd) {
                if( !array_key_exists($fd['key'], $arrExistingFields) ) {
                    $arrCreates[] = $fd;
                } elseif( $arrExistingFields[$fd['key']] !== $fd['name'] ) {
                    $arrUpdates[] = $fd;
                }
            }
        }
        // ($arrExistingFields === null → couldn't introspect the fields: instance-based fallback → no field reconcile)

        if( empty($arrCreates) && empty($arrUpdates) && !$enableTranslatable ) {
            return;
        }

        $response =
            $this
                ->setQueryFromTemplate([
                    'id'                 => $def['id'],
                    'creates'            => $arrCreates,
                    'updates'            => $arrUpdates,
                    'enableTranslatable' => $enableTranslatable,
                ], 'metaobject-definition-update', true)
                ->connector->send($this);

        $oResponse      = $this->buildFromResponse($response);
        $arrUserErrors  = $oResponse->data->metaobjectDefinitionUpdate->userErrors ?? [];
        $this->throwOnUserErrors('metaobjectDefinitionUpdate', $arrUserErrors);

        // invalidate the cached definition so its fields are re-read on next access
        unset($this->arrTypeDefinitions[$type]);

        $this->arrLastReconciledFieldKeys = array_map(fn(array $fd) => $fd['key'], array_merge($arrCreates, $arrUpdates));
    }


    /** Delete a metaobject definition and ALL its instances. Irreversible — primarily for teardown/cleanup. */
    public function deleteDefinition(string $type) : void
    {
        $def = $this->getDefinition($type);

        $response =
            $this
                ->setQueryFromTemplate(['id' => $def['id']], 'metaobject-definition-delete', true)
                ->connector->send($this);

        $oResponse      = $this->buildFromResponse($response);
        $arrUserErrors  = $oResponse->data->metaobjectDefinitionDelete->userErrors ?? [];
        $this->throwOnUserErrors('metaobjectDefinitionDelete', $arrUserErrors);

        unset($this->arrTypeDefinitions[$type]);
    }


    /**
     * Create a metaobject definition. Prefer ensureDefinition() unless you know it doesn't exist.
     * $translatable enables the definition-level translatable capability (see ensureDefinition).
     *
     * @param array<int,array{key:string,name:string,type:string}> $fieldDefinitions
     */
    public function createDefinition(
        string $type, string $name, array $fieldDefinitions, string $displayNameKey = 'label', bool $translatable = false
    ) : string
    {
        $response =
            $this
                ->setQueryFromTemplate([
                    'type'           => $type,
                    'name'           => $name,
                    'displayNameKey' => $displayNameKey,
                    'fieldDefinitions' => $fieldDefinitions,
                    'translatable'   => $translatable,
                ], 'metaobject-definition-create', true)
                ->connector->send($this);

        $oResponse      = $this->buildFromResponse($response);
        $arrUserErrors  = $oResponse->data->metaobjectDefinitionCreate->userErrors ?? [];
        $this->throwOnUserErrors('metaobjectDefinitionCreate', $arrUserErrors);

        $id = $oResponse->data->metaobjectDefinitionCreate->metaobjectDefinition->id ?? null;
        if( empty($id) ) {
            throw new ShopifyResponseException('metaobjectDefinitionCreate returned no id');
        }

        // seed the definition cache so a subsequent getDefinition()/create() doesn't re-fetch
        $this->arrTypeDefinitions[$type] = [
            'id'                  => $id,
            'displayNameFieldKey' => $displayNameKey,
            'fields'              => array_column($fieldDefinitions, 'name', 'key'),
            'translatable'        => $translatable,
        ];

        return $id;
    }


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
            $arrFields = [];   // [key => name] — name drives the reconcile rename diff
            foreach($def->fieldDefinitions ?? [] as $fd) {
                if( !empty($fd->key) ) {
                    $arrFields[$fd->key] = $fd->name ?? '';
                }
            }

            return $this->arrTypeDefinitions[$type] = [
                'id'                  => $def->id,
                'displayNameFieldKey' => $def->displayNameKey ?? null,
                'fields'              => $arrFields,
                'translatable'        => (bool)($def->capabilities->translatable->enabled ?? false),
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

        // instance-based fallback can't introspect the definition's field list nor its capabilities → both null
        // (unknown), so the reconcile leaves fields AND the translatable capability alone
        return $this->arrTypeDefinitions[$type] = [
            'id'                  => $defEdge->id,
            'displayNameFieldKey' => $defEdge->displayNameKey ?? null,
            'fields'              => null,
            'translatable'        => null,
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


    /**
     * Find the instance whose field $fieldKey holds $value (a stable identity match, e.g. brand_id, that
     * survives display-name renames). Field values are always strings in the Admin API, so $value is compared
     * as a string. Returns null when no instance matches.
     */
    public function findByFieldValue(string $type, string $fieldKey, string $value) : ?\stdClass
    {
        foreach($this->listByType($type) as $mo) {
            foreach($mo->fields ?? [] as $field) {
                if( ($field->key ?? null) === $fieldKey && (string)($field->value ?? '') === $value ) {
                    return $mo;
                }
            }
        }
        return null;
    }


    /**
     * Find-or-create by display name — for metaobjects whose NAME is their identity (i.e. they carry no stable
     * external id). When an item has a stable identity field, use findOrCreateByField() instead and match on the
     * id, never on the (mutable) name.
     */
    public function findOrCreateByDisplayName(string $type, string $displayName, ?string $status = null) : string
    {
        $existing = $this->findByDisplayName($type, $displayName);
        if($existing !== null) {
            return $existing->id;
        }
        return $this->create($type, $displayName, $status);
    }


    /**
     * Find-or-create keyed on a stable identity FIELD (e.g. an external id), never the display name — the
     * recommended way to match an external record to its metaobject. On a match the existing instance is reused
     * as-is (its GID, so every reference survives); on a miss it's created with the display name + $fields.
     * $fields MUST contain $matchFieldKey.
     * Display-name changes for an already-existing item are propagated by the owning command's update path
     * (updateDisplayName), not here.
     *
     * @param array<string,scalar> $fields  non-display field values (incl. the match field), keyed by field key
     */
    public function findOrCreateByField(string $type, string $matchFieldKey, string $displayName, array $fields, ?string $status = null) : string
    {
        if( !array_key_exists($matchFieldKey, $fields) ) {
            throw new \InvalidArgumentException("findOrCreateByField: \$fields must contain the match key \"$matchFieldKey\"");
        }

        $existing = $this->findByFieldValue($type, $matchFieldKey, (string)$fields[$matchFieldKey]);
        if($existing !== null) {
            return $existing->id;
        }

        return $this->create($type, $displayName, $status, $fields);
    }


    /**
     * Create a metaobject. When `$status` is null (default), Shopify uses its server-side default (DRAFT for
     * publishable definitions). Pass 'ACTIVE' to publish immediately. Pass 'DRAFT' to be explicit. The value is
     * embedded as a GraphQL enum literal — no quoting — so any custom string that resolves to a valid
     * `MetaobjectStatus` value works.
     */
    public function create(string $type, string $displayName, ?string $status = null, array $extraFields = []) : string
    {
        $fieldKey = $this->resolveDisplayNameFieldKey($type);

        $arrFields = [['key' => $fieldKey, 'value' => $displayName]];
        foreach($extraFields as $key => $value) {
            if($key === $fieldKey) {
                continue; // the display name is already set above
            }
            $arrFields[] = ['key' => $key, 'value' => (string)$value];
        }

        $response =
            $this
                ->setQueryFromTemplate([
                    'type'   => $type,
                    'status' => $status,
                    'fields' => $arrFields,
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
