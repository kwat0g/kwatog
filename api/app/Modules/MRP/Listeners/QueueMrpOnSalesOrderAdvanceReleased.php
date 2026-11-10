<?php

declare(strict_types=1);

namespace App\Modules\MRP\Listeners;

use App\Modules\CRM\Events\SalesOrderAdvanceReleased;
use App\Modules\MRP\Jobs\RunAutomaticMrpJob;

final class QueueMrpOnSalesOrderAdvanceReleased
{
    public function handle(SalesOrderAdvanceReleased $event): void
    {
        RunAutomaticMrpJob::dispatch([(int) $event->salesOrder->getKey()], 'sales_order_advance_released');
    }
}
