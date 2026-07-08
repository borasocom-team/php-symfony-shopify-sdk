<?php
namespace TurboLabIt\ShopifySdk\Request;

use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use TurboLabIt\ShopifySdk\Exception\ShopifyResponseException;


/**
 * Runs a GraphQL mutation in bulk: stages a JSONL variables file, fires bulkOperationRunMutation, polls the
 * operation to completion (by GID) and returns the decoded result lines (one per input). Generic — the caller
 * supplies the mutation string and the per-row variable objects.
 *
 * Only one bulk operation runs per shop at a time, so callers must serialize this with any other bulk op.
 */
class ShopifyBulkMutationRequest extends ShopifyBaseAdminRequest
{
    const string UPLOAD_FILENAME        = 'bulk_op_variables.jsonl';
    const string STAGED_UPLOAD_TEMPLATE = 'staged-uploads-create';

    protected string $templateFile = 'bulk-operation-run-mutation';

    protected ?string $primaryLocationGid = null;


    /**
     * @param string $mutation           the mutation to run once per input, e.g.
     *                                    'mutation call($product: ProductUpdateInput!) { productUpdate(product: $product) {...} }'
     * @param array  $arrVariableObjects one object per input; each becomes a JSONL line of variables whose
     *                                    top-level keys match the mutation's variables
     * @return \stdClass[]               the decoded result JSONL lines (empty when there is nothing to do)
     */
    public function run(string $mutation, array $arrVariableObjects) : array
    {
        if( empty($arrVariableObjects) ) {
            return [];
        }

        $jsonl   = $this->buildJsonl($arrVariableObjects);
        $oTarget = $this->createStagedTarget();
        $this->uploadToStagedTarget($oTarget, $jsonl);

        $response =
            $this
                ->setQueryFromTemplate([
                    'mutation'         => $mutation,
                    'stagedUploadPath' => $this->extractStagedPath($oTarget),
                ], null, true)
                ->connector->send($this);

        // buildFromBulkResponse() re-reads the submit response (parseResponse throws on bulkOperationRunMutation
        // userErrors), extracts the bulk-op GID, polls it via node(id:) and downloads the results JSONL.
        return $this->buildFromBulkResponse($response, true);
    }


    protected function buildJsonl(array $arrVariableObjects) : string
    {
        return implode("\n", array_map(
            fn($oVariables) => json_encode($oVariables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $arrVariableObjects
        ));
    }


    protected function createStagedTarget() : \stdClass
    {
        $response =
            $this
                ->setQueryFromTemplate(['filename' => static::UPLOAD_FILENAME], static::STAGED_UPLOAD_TEMPLATE, true)
                ->connector->send($this);

        $oResponse  = $this->buildFromResponse($response);
        $oCreate    = $oResponse->data->stagedUploadsCreate ?? null;

        $arrUserErrors = $oCreate->userErrors ?? [];
        if( !empty($arrUserErrors) ) {
            throw new ShopifyResponseException('stagedUploadsCreate userErrors: ' . $this->stringifyUserErrors($arrUserErrors));
        }

        $oTarget = $oCreate->stagedTargets[0] ?? null;
        if( empty($oTarget->url) ) {
            throw new ShopifyResponseException('stagedUploadsCreate returned no staged target URL');
        }

        return $oTarget;
    }


    protected function uploadToStagedTarget(\stdClass $oTarget, string $jsonl) : void
    {
        $arrFields = [];
        foreach($oTarget->parameters ?? [] as $oParam) {
            $arrFields[$oParam->name] = $oParam->value;
        }

        // the file part MUST be last for the storage backend (Google Cloud Storage)
        $arrFields['file'] = new DataPart($jsonl, static::UPLOAD_FILENAME, 'text/jsonl');

        $formDataPart = new FormDataPart($arrFields);

        $response =
            $this->getHttpClient()->request('POST', $oTarget->url, [
                'headers'   => $formDataPart->getPreparedHeaders()->toArray(),
                'body'      => $formDataPart->bodyToIterable(),
            ]);

        $httpStatusCode = $response->getStatusCode();
        if( $httpStatusCode < 200 || $httpStatusCode >= 300 ) {
            throw new ShopifyResponseException(sprintf(
                'Staged upload POST failed: HTTP %d — %s', $httpStatusCode, $response->getContent(false)
            ));
        }
    }


    protected function extractStagedPath(\stdClass $oTarget) : string
    {
        foreach($oTarget->parameters ?? [] as $oParam) {
            if( $oParam->name === 'key' ) {
                return $oParam->value;
            }
        }

        throw new ShopifyResponseException('Staged target is missing the "key" parameter (stagedUploadPath)');
    }


    /**
     * Resolve (and cache) the GID of the location whose inventory we drive — the first location returned (the
     * connector targets a single-location store). Throws rather than silently skipping inventory. Shared by every
     * bulk-mutation request that has to address a location (productSet, inventorySetQuantities, variant create).
     */
    public function getPrimaryLocationGid() : string
    {
        if( $this->primaryLocationGid !== null ) {
            return $this->primaryLocationGid;
        }

        $query     = 'query { locations(first: 1) { edges { node { id } } } }';
        $response  = $this->setQuery($query, true)->connector->send($this);
        $oResponse = $this->buildFromResponse($response);

        $gid = $oResponse->data->locations->edges[0]->node->id ?? null;
        if( empty($gid) ) {
            throw new \RuntimeException(static::class . ': could not resolve a primary Shopify location for inventory.');
        }

        return $this->primaryLocationGid = $gid;
    }


    protected function stringifyUserErrors(array $arrUserErrors) : string
    {
        return implode('; ', array_map(
            fn($oneError) => $this->formatUserError($oneError),
            $arrUserErrors
        ));
    }


    /**
     * Per-line GraphQL errors inside a bulk-op result: a row can fail at the GRAPHQL level (top-level `errors`
     * array on its result line, `data` null, NO userErrors) — e.g. an input-shape rejection after an API-version
     * bump. Every collectRowErrors() must merge these in: reading only userErrors makes such a row report
     * SUCCESS while writing nothing (a silent no-op, seen live on inventorySetQuantities under API 2026-04).
     *
     * @return string[] one message per GraphQL error on the line (empty when the line has none)
     */
    protected function lineGraphqlErrors(\stdClass $oLine) : array
    {
        $arrErrors = [];
        foreach($oLine->errors ?? [] as $oError) {
            $arrErrors[] = (string)($oError->message ?? json_encode($oError));
        }

        return $arrErrors;
    }
}
