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
        // MRP's planningRelevant scope is the authoritative release gate: it
        // excludes orders whose latest deposit is configured, pending, or
        // rejected. The normal confirmation trigger remains unchanged for
        // orders without a deposit; a verified/waived deposit publishes its
        // own one-time release event.
        RunAutomaticMrpJob::dispatch([(int) $event->salesOrder->getKey()], 'sales_order_confirmed');
    }
}
