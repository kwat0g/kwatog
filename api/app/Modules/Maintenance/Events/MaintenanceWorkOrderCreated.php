<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Events;

use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Internal domain event used by maintenance notification listeners. Work-order
 * refresh delivery is owned by MaintenanceWorkOrderChanged so create and
 * subsequent mutations use the same authorized signal path.
 */
class MaintenanceWorkOrderCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public MaintenanceWorkOrder $workOrder) {}
}
