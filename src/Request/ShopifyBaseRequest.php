<?php
namespace TurboLabIt\ShopifySdk\Request;

use Saloon\Http\Request;
use Saloon\Traits\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Contracts\Body\HasBody as HasBodyContract;
use TurboLabIt\ShopifySdk\Exception\ShopifyConfigurationException;
use Saloon\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use TurboLabIt\ShopifySdk\Exception\ShopifyResponseException;


abstract class ShopifyBaseRequest extends Request implements HasBodyContract
{
    protected Method $method = Method::POST;

    protected string $templateDir   = 'request/shopify/graphql/';
    protected string $templateFile  = '';

    use HasBody;


    public function setQueryFromTemplateBuilt(array $arrData = []) : static
    {
        if( empty($this->templateFile) ) {
            throw new ShopifyConfigurationException("$this->templateFile not set!");
        }

        $arrData["shopify_config"] = $this->arrConfig;

        $template = $this->templateDir . $this->templateFile . ".graphql.twig";
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


    public function buildFromResponse(Response $response)
    {
        $errorMessages = [];

        $httpStatusCode = $response->status() ?? null;

        if(
            empty($httpStatusCode) ||
            $httpStatusCode < SymfonyResponse::HTTP_OK ||
            $httpStatusCode >= SymfonyResponse::HTTP_MULTIPLE_CHOICES
        ) {
            $errorMessages[] = "HTTP response error: ##$httpStatusCode##";
        }

        try {
            $arrResponse = $response->json();
        } catch (\JsonException $ex) {
            $errorMessages[] = "JSON decode error: ##" . $response->body() . "##";
        }

        if( !empty($arrResponse) && is_array($arrResponse) && !empty($arrResponse["errors"]) ) {

            $arrErrorFromJson   = array_column($arrResponse["errors"], 'message') ?? null;
            $errorMessages      = array_merge($errorMessages, $arrErrorFromJson);
        }

        if( !empty($errorMessages) ) {

            $message = implode(PHP_EOL, $errorMessages);
            throw new ShopifyResponseException($message, $httpStatusCode);
        }

        return $arrResponse;
    }
}
