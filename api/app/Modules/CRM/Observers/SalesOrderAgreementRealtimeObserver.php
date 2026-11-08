<?php

declare(strict_types=1);

namespace App\Modules\CRM\Observers;

use App\Common\Services\ChainBroadcaster;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderAgreement;

/** Refreshes every authorized Sales Order and embedded Return Case view. */
final class SalesOrderAgreementRealtimeObserver
{
    public function created(SalesOrderAgreement $agreement): void
    {
        $this->refreshOrder($agreement);
    }

    public function updated(SalesOrderAgreement $agreement): void
    {
        $this->refreshOrder($agreement);
    }

    private function refreshOrder(SalesOrderAgreement $agreement): void
    {
        $order = SalesOrder::withTrashed()->findOrFail($agreement->sales_order_id);

        app(ChainBroadcaster::class)->refreshSalesOrder($order);
    }
}
