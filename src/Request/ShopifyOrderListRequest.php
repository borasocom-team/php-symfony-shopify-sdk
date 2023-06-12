<?php
namespace TurboLabIt\ShopifySdk\Request;


class ShopifyOrderListRequest extends ShopifyBaseAdminRequest
{
    protected string $templateFile = 'orders-bulk';


    public function getRecent(int $lastDays = 7) : array
    {
        $date =
            (new \DateTime())
                ->modify('-' . $lastDays . ' days')
                ->format('Y-m-d');

        $request =
            $this
                ->setQueryFromTemplateBuilt([
                    "selectOrderAfterDate"  => $date
                ]);

        $response = $this->connector->send($request);

        $arrResponse    = $this->buildFromResponse($response);
        $arrOrders      = $arrResponse["data"]["orders"]["edges"] ?? null;

        if( empty($arrOrders) ) {
            return [];
        }

        $arrOrders      = array_column($arrOrders, 'node');

        return $arrOrders;
    }
}
