<?php
namespace TurboLabIt\ShopifySdk\Request;

use Saloon\Http\Request;
use Saloon\Traits\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Contracts\Body\HasBody as HasBodyContract;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TurboLabIt\ShopifySdk\Exception\ShopifyConfigurationException;
use Saloon\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use TurboLabIt\ShopifySdk\Exception\ShopifyResponseException;


abstract class ShopifyBaseRequest extends Request implements HasBodyContract
{
    const BULK_OP_STATUS_DONE   = 'COMPLETED';
    const BULK_OP_STATUS_FAIL   = 'FAILED';
    const BULK_STATUS_TEMPLATE  = '@ShopifySdk/request/shopify/graphql/bulk-operation-status';

    protected Method $method = Method::POST;

    protected string $templateDir   = '@ShopifySdk/request/shopify/graphql/';
    protected string $templateFile  = '';

    protected HttpClientInterface $httpClient;

    protected array $arrCachedData = [];

    protected ?\Closure $consoleSink = null;


    use HasBody;


    protected function buildCacheKey(string $methodName, mixed $input = null) : string
    {
        if( is_array($input) ) {
            ksort($input);
        }

        return $methodName . '.' . hash('xxh3', serialize($input));
    }


    /**
     * Console sink for operational progress: fn(string $level, string $message), levels 'info'|'warning'.
     * Today only the async bulk-operation poll emits through it (see buildFromBulkResponse). No sink (the
     * default) = fully silent, the pre-existing behavior. Pass null to clear — callers should do so in a
     * finally, to break the caller ↔ request ↔ closure reference cycle.
     */
    public function setConsoleSink(?callable $sink) : static
    {
        $this->consoleSink = $sink !== null ? \Closure::fromCallable($sink) : null;
        return $this;
    }


    protected function emitToConsoleSink(string $message, string $level = 'info') : void
    {
        if( $this->consoleSink !== null ) {
            ($this->consoleSink)($level, $message);
        }
    }


    protected function formatElapsedMinSec(int $seconds) : string
        { return sprintf('%d:%02d min', intdiv($seconds, 60), $seconds % 60); }


    /**
     * Format one GraphQL userError as "field: message". Shopify types `userErrors.field` as `[String!]` — a PATH
     * into the input (e.g. ["variants","1","inventoryItem","barcode"]), NOT a scalar — so it MUST be joined, never
     * string-concatenated: `(array) . ': '` raises a "Array to string conversion" warning, which Symfony's
     * ErrorHandler escalates to a fatal that MASKS the very message being reported (and aborts a whole bulk-row
     * batch over one bad row). Tolerates `field` arriving as the documented array, a bare scalar, or null/absent.
     */
    protected function formatUserError(object $oError) : string
    {
        $field = $oError->field ?? '';
        if( is_array($field) ) {
            $field = implode('.', $field);
        }

        return (string)$field . ': ' . ($oError->message ?? '?');
    }


    public function setQueryFromTemplate(array $arrData = [], ?string $overrideTemplateName = null, bool $queryIsJson = false) : static
    {
        if( empty($this->templateFile) ) {
            throw new ShopifyConfigurationException("$this->templateFile not set!");
        }

        $arrData["shopify_config"] = $this->arrConfig;

        // names targeting a Twig namespace (e.g. '@ShopifySdk/...') are absolute — don't prepend templateDir,
        // so a request that overrides templateDir for its own queries can still reach shared SDK templates
        $templateName   = $overrideTemplateName ?? $this->templateFile;
        $templateDir    = str_starts_with($templateName, '@') ? '' : $this->templateDir;
        $template       = $templateDir . $templateName . ".graphql.twig";
        $graphQlQuery   = $this->twig->render($template, $arrData);

        return $this->setQuery($graphQlQuery, $queryIsJson);
    }


    public function setQuery(string $graphQl, bool $queryIsJson = false) : static
    {
        if($queryIsJson) {
            $graphQl = json_encode(
                ['query' => $graphQl]
            );
        }
        $this->body()->set($graphQl);
        return $this;
    }


    public function resolveEndpoint(): string { return ''; }


    public function buildFromResponse(Response $response) : \stdClass
    {
        $errorMessages  = [];
        $oResponse      = $this->parseResponse($response, $errorMessages);
        $this->throwOnErrors($errorMessages, $response);

        return $oResponse;
    }


    public function buildFromBulkResponse(Response $response,  bool $queryIsJson = false) : array
    {
        $errorMessages  = [];
        $oSubmit        = $this->parseResponse($response, $errorMessages);
        $this->throwOnErrors($errorMessages, $response);

        // currentBulkOperation is deprecated (API 2026-04) and bulkOperations sort/reverse is unreliable for
        // "most recent", so poll the exact operation we just started, by GID, via node(id:).
        $bulkOpId =
            $oSubmit->data->bulkOperationRunQuery->bulkOperation->id
            ?? $oSubmit->data->bulkOperationRunMutation->bulkOperation->id
            ?? null;

        // While the operation is running, you need to poll to see its progress
        $pollStartedAt  = time();
        $pollCount      = 0;

        do {
            $response =
                $this
                    ->setQueryFromTemplate(['bulkOpId' => $bulkOpId], static::BULK_STATUS_TEMPLATE, $queryIsJson)
                    ->connector->send($this);

            $errorMessages  = [];
            $oResponse      = $this->parseResponse($response, $errorMessages);
            $this->throwOnErrors($errorMessages, $response);

            $oCurrentOp     = $oResponse->data->node ?? null;
            $bulkOpStatus   = $oCurrentOp->status ?? null;
            $bulkOpIsDone   = strtoupper((string)$bulkOpStatus) == static::BULK_OP_STATUS_DONE;
            $pollCount++;

            if( !$bulkOpIsDone ) {

                // heartbeat, so a long-running operation is distinguishable from a hang in the (cron) log:
                // on the first poll, then ~every 30 s (the poll interval is 2 s). Silent unless a sink is wired.
                if( $pollCount == 1 || $pollCount % 15 == 0 ) {
                    $this->emitToConsoleSink(sprintf(
                        'bulk operation %s — ##%s## object(s) processed so far, elapsed %s',
                        (string)$bulkOpStatus, (string)($oCurrentOp->objectCount ?? '?'),
                        $this->formatElapsedMinSec(time() - $pollStartedAt)
                    ));
                }

                sleep(2);
            }

        } while(!$bulkOpIsDone);

        $this->emitToConsoleSink(sprintf(
            'bulk operation completed — ##%s## object(s) in %s',
            (string)($oCurrentOp->objectCount ?? '?'), $this->formatElapsedMinSec(time() - $pollStartedAt)
        ));

        // When an operation is completed, a JSONL output file is available for download at the URL specified in the url field
        $dataUrl = $oCurrentOp->url ?? null;

        // there are NO items
        if( empty($dataUrl) ) {
            return [];
        }

        $txtJson =
            $this->getHttpClient()
                ->request('GET', $dataUrl)
                ->getContent();

        if( empty($txtJson) ) {
            return [];
        }

        $arrLines = explode(PHP_EOL, trim($txtJson));

        $arrData = [];
        foreach($arrLines as $oneJsonLine) {
            $arrData[] = json_decode($oneJsonLine, false, 255, JSON_THROW_ON_ERROR);
        }

        return $arrData;
    }


    protected function parseResponse(Response $response, array &$arrErrorMessages) : \stdClass
    {
        $httpStatusCode = $response->status() ?? null;

        if(
            empty($httpStatusCode) ||
            $httpStatusCode < SymfonyResponse::HTTP_OK ||
            $httpStatusCode >= SymfonyResponse::HTTP_MULTIPLE_CHOICES
        ) {
            $arrErrorMessages[] = "HTTP response error: ##$httpStatusCode##";
        }

        try {
            $oResponse = $response->object();

        } catch (\JsonException $ex) {

            $oResponse = null;
            $arrErrorMessages[] = "JSON decode error (##" . $ex->getMessage() . "##): ##" . $response->body() . "##";
        }

        if( !empty($oResponse->errors) ) {

            if( is_string($oResponse->errors) ) {

                $arrErrorMessages[] = $oResponse->errors;

            } else {

                foreach($oResponse->errors as $oneError) {
                    $arrErrorMessages[] = $oneError->message ?? json_encode($oneError);
                }
            }
        }

        if( !empty($oResponse->data->bulkOperationRunQuery->userErrors) ) {

            foreach($oResponse->data->bulkOperationRunQuery->userErrors as $oneError) {
                $arrErrorMessages[] = $oneError->message;
            }
        }

        if( !empty($oResponse->data->bulkOperationRunMutation->userErrors) ) {

            foreach($oResponse->data->bulkOperationRunMutation->userErrors as $oneError) {
                $arrErrorMessages[] = $oneError->message;
            }
        }

        // currentBulkOperation is deprecated (API 2026-04) → we poll the op by GID via node(id:)
        $oBulkOp    = $oResponse->data->node ?? null;
        $bulkStatus = $oBulkOp->status ?? null;
        if( !empty($bulkStatus) && strtoupper($bulkStatus) ==  static::BULK_OP_STATUS_FAIL ) {

            $bulkErrorCode = $oBulkOp->errorCode ?? null;
            $arrErrorMessages[] = "Bulk operation failed: ##$bulkErrorCode##";
        }

        return $oResponse;
    }


    protected function throwOnErrors(array $errorMessages, Response $response) : void
    {
        if( !empty($errorMessages) ) {

            $httpStatusCode = $response->status() ?? 0;
            $message        = implode(PHP_EOL, $errorMessages);
            throw new ShopifyResponseException($message, $httpStatusCode);
        }
    }


    protected function getHttpClient() : HttpClientInterface
    {
        if( !empty($this->httpClient) ) {
            return $this->httpClient;
        }

        $this->httpClient = HttpClient::create();
        return $this->httpClient;
    }


    public function clearCache() : static
    {
        $this->arrCachedData = [];
        return $this;
    }
}
