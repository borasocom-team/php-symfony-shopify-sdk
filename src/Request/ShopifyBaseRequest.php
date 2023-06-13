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

    protected Method $method = Method::POST;

    protected string $templateDir   = '@ShopifySdk/request/shopify/graphql/';
    protected string $templateFile  = '';

    protected HttpClientInterface $httpClient;

    use HasBody;


    public function setQueryFromTemplate(array $arrData = [], ?string $overrideTemplateName = null) : static
    {
        if( empty($this->templateFile) ) {
            throw new ShopifyConfigurationException("$this->templateFile not set!");
        }

        $arrData["shopify_config"] = $this->arrConfig;

        $template = $this->templateDir . ($overrideTemplateName ?? $this->templateFile) . ".graphql.twig";
        $graphQlQuery = $this->twig->render($template, $arrData);

        return $this->setQuery($graphQlQuery);
    }


    public function setQuery(string $graphQl) : static
    {
        $this->body()->set($graphQl);
        return $this;
    }


    public function resolveEndpoint(): string
    {
        return '';
    }


    public function buildFromResponse(Response $response) : \stdClass
    {
        $errorMessages  = [];
        $oResponse      = $this->parseResponse($response, $errorMessages);
        $this->throwOnErrors($errorMessages, $response);

        return $oResponse;
    }


    public function buildFromBulkResponse(Response $response) : array
    {
        $errorMessages  = [];
        $this->parseResponse($response, $errorMessages);
        $this->throwOnErrors($errorMessages, $response);

        // While the operation is running, you need to poll to see its progress
        do {
            $response =
                $this
                    ->setQueryFromTemplate([], 'bulk-operation-status')
                    ->connector->send($this);

            $errorMessages  = [];
            $oResponse      = $this->parseResponse($response, $errorMessages);
            $this->throwOnErrors($errorMessages, $response);

            $bulkOpStatus   = $oResponse->data->currentBulkOperation->status ?? null;
            $bulkOpIsDone   = strtoupper($bulkOpStatus) == static::BULK_OP_STATUS_DONE;

            if( !$bulkOpIsDone ) {
                sleep(2);
            }

        } while(!$bulkOpIsDone);

        // When an operation is completed, a JSONL output file is available for download at the URL specified in the url field
        $dataUrl = $oResponse->data->currentBulkOperation->url ?? null;

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

            foreach($oResponse->errors as $oneError) {
                $arrErrorMessages[] = $oneError->message;
            }
        }

        if( !empty($oResponse->data->bulkOperationRunQuery->userErrors) ) {

            foreach($oResponse->data->bulkOperationRunQuery->userErrors as $oneError) {
                $arrErrorMessages[] = $oneError->message;
            }
        }

        $bulkStatus = $oResponse->data->currentBulkOperation->status ?? null;
        if( !empty($bulkStatus) && strtoupper($bulkStatus) ==  static::BULK_OP_STATUS_FAIL ) {

            $bulkErrorCode = $oResponse->data->currentBulkOperation->errorCode ?? null;
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
}
