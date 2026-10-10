<?php

declare(strict_types=1);

namespace App\Modules\Production\Listeners;

use App\Modules\Production\Events\WorkOrderQualityResolved;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;

class AutoCloseResolvedWorkOrder implements ShouldQueue
{
    public function handle(WorkOrderQualityResolved $event): void
    {
        app(WorkOrderService::class)->autoCloseIfReady($event->workOrder, $event->actor);
    }
}
