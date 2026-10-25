<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Maintenance\Events\MaintenanceWorkOrderCreated;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\MRP\Events\MoldShotLimitNearing;
use App\Modules\MRP\Events\MoldShotLimitReached;
use App\Modules\MRP\Events\MrpPlanGenerated;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Production\Events\MachineBreakdownDetected;
use App\Modules\Production\Events\WorkOrderOutputRecorded;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use Tests\TestCase;

class DashboardBroadcastPayloadTest extends TestCase
{
    public function test_shared_dashboard_events_only_send_a_refresh_ping(): void
    {
        $workOrder = new WorkOrder;
        $machine = new Machine;
        $mold = new Mold;

        $events = [
            new WorkOrderStatusChanged($workOrder, 'confirmed', 'in_progress', 'Sensitive reason'),
            new WorkOrderOutputRecorded($workOrder, new WorkOrderOutput),
            new MachineBreakdownDetected($machine, $workOrder, [['id' => 'candidate']], 'Sensitive reason'),
            new MachineStatusChanged($machine, 'idle', 'maintenance', 'Sensitive reason'),
            new MoldShotLimitNearing($mold),
            new MoldShotLimitReached($mold),
            new MrpPlanGenerated(new MrpPlan),
            new MaintenanceWorkOrderCreated(new MaintenanceWorkOrder),
        ];

        foreach ($events as $event) {
            $this->assertSame(['refresh' => true], $event->broadcastWith(), $event::class);
        }
    }
}
