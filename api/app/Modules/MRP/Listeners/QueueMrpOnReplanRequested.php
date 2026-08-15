<?php

declare(strict_types=1);

namespace App\Modules\MRP\Listeners;

use App\Modules\MRP\Events\MrpReplanRequested;
use App\Modules\MRP\Jobs\RunAutomaticMrpJob;

class QueueMrpOnReplanRequested
{
    public function handle(MrpReplanRequested $event): void
    {
        RunAutomaticMrpJob::dispatch($event->salesOrderIds, $event->reason);
    }
}
