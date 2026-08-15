<?php

declare(strict_types=1);

namespace App\Modules\MRP\Listeners;

use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\MRP\Jobs\RunAutomaticMrpJob;

/** Starts MRP only after the confirmed SO transaction has committed. */
class QueueMrpOnSalesOrderConfirmed
{
    public function handle(SalesOrderConfirmed $event): void
    {
        RunAutomaticMrpJob::dispatch([(int) $event->salesOrder->getKey()], 'sales_order_confirmed');
    }
}
