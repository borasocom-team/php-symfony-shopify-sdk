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

        $arrOrders = $this->buildFromResponse($response);

        return $arrOrders;
    }
}
