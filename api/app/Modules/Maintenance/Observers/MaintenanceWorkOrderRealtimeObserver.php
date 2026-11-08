<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Observers;

use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderRealtimeService;

final class MaintenanceWorkOrderRealtimeObserver
{
    public function created(MaintenanceWorkOrder $order): void
    {
        app(MaintenanceWorkOrderRealtimeService::class)->stage($order, 'created');
    }

    public function updated(MaintenanceWorkOrder $order): void
    {
        app(MaintenanceWorkOrderRealtimeService::class)->stage($order, 'updated');
    }

    public function deleted(MaintenanceWorkOrder $order): void
    {
        app(MaintenanceWorkOrderRealtimeService::class)->stage($order, 'deleted');
    }
}
