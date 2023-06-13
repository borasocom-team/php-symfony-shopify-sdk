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

        $response =
            $this
                ->setQueryFromTemplate([
                    "selectOrderAfterDate"  => $date
                ])
                ->connector->send($this);

        $arrJsons = $this->buildFromBulkResponse($response);

        if( empty($arrJsons) ) {
            return [];
        }

        $arrOrders = [];

        // In the JSONL results, each order object is followed by each of products and its variant objects on a new line
        foreach($arrJsons as $oneItem) {

            // it's an ORDER
            if( !empty($oneItem->id) && stripos($oneItem->id, '/shopify/Order/') !== false ) {

                $orderId = str_ireplace('gid://shopify/Order/', '', $oneItem->id);
                $arrOrders[$orderId]["Order"] = $oneItem;

            // it's a PRODUCT
            } elseif( !empty($oneItem->name) && !empty($oneItem->quantity)) {

                $orderId = str_ireplace('gid://shopify/Order/', '', $oneItem->__parentId);
                $arrOrders[$orderId]["Products"][] = $oneItem;

            } else {

                throw new ShopifyResponseException('Unhandled case in order response');
            }
        }

        return $arrOrders;
    }
}
