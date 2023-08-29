<?php
namespace TurboLabIt\ShopifySdk\DataAdapter;


class OrderDataAdapter
{
    public function buildUtmFromCustomerJourneySummary(?\stdClass $customerJourneySummary) : ?\stdClass
    {
        foreach(["lastVisit", "firstVisit"] as $visitName) {

            $visitUtmParams = $customerJourneySummary->$visitName->utmParameters ?? null;
            if( !empty($visitUtmParams) ) {
                return $visitUtmParams;
            }
        }

        return null;
    }
}
