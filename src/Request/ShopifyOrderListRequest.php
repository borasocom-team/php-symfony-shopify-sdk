<?php
namespace TurboLabIt\ShopifySdk\Request;

use TurboLabIt\ShopifySdk\Exception\ShopifyResponseException;


class ShopifyOrderListRequest extends ShopifyBaseAdminRequest
{
    const RECENT_DAYS_DEFAULT_NUM = 7;

    protected string $templateFile = 'orders-bulk';


    public function getRecent(?int $lastDays = null) : array
    {
        $lastDays = is_null($lastDays) ? static::RECENT_DAYS_DEFAULT_NUM : $lastDays;
        if($lastDays > 0) {

            $date =
                (new \DateTime())
                    ->modify('-' . $lastDays . ' days')
                    ->format('Y-m-d');

            $arrParams = ["shopifyOrdersQuery" => "updated_at:>$date"];
        }

        $response =
            $this
                ->setQueryFromTemplate($arrParams ?? [], null, true)
                ->connector->send($this);

        $arrJsons = $this->buildFromBulkResponse($response,true);

        if( empty($arrJsons) ) {
            return [];
        }

        $arrOrders = [];

        // In the JSONL results, each order object is followed by each of products and its variant objects on a new line
        foreach($arrJsons as $oneItem) {

            // it's an ORDER
            if( !empty($oneItem->id) && stripos($oneItem->id, '/shopify/Order/') !== false ) {

                $orderId = str_ireplace('gid://shopify/Order/', '', $oneItem->id);
                $arrOrders[$orderId] = $oneItem;

            // it's an ORDER_ITEM
            } elseif( !empty($oneItem->name) && !empty($oneItem->quantity)) {

                $orderId = str_ireplace('gid://shopify/Order/', '', $oneItem->__parentId);
                $arrOrders[$orderId]->Items[] = $oneItem;

            // FULFILLMENT data
            } elseif( !empty($oneItem->id) && stripos($oneItem->id, '/shopify/FulfillmentOrder/') !== false) {

                $orderId = str_ireplace('gid://shopify/Order/', '', $oneItem->__parentId);
                $arrOrders[$orderId]->fulfillment[] = [
                    'id'      => str_ireplace('gid://shopify/FulfillmentOrder/', '', $oneItem->id),
                    'status'  => $oneItem->status,
                    'location'=> $oneItem->assignedLocation->name
                ];

            } elseif( !empty($oneItem->key) ) {

                $orderId = str_ireplace('gid://shopify/Order/', '', $oneItem->__parentId);
                $key = $oneItem->key;
                $arrOrders[$orderId]->$key[] = $oneItem;

            } else {

                throw new ShopifyResponseException('Unhandled case in order response');
            }
        }

        return $arrOrders;
    }
}
