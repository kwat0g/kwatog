<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Observers;

use App\Modules\Maintenance\Models\MaintenanceLog;
use App\Modules\Maintenance\Models\MaintenancePartRequest;
use App\Modules\Maintenance\Models\MaintenanceWindow;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Models\SparePartUsage;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderRealtimeService;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Services\AssignedWorkOrderRefreshService;
use Illuminate\Database\Eloquent\Model;

/** Refresh the owning WO when a displayed log, part, window or downtime changes. */
final class MaintenanceWorkOrderChildRealtimeObserver
{
    public function created(Model $child): void
    {
        $this->stage($child, 'updated');
    }

    public function updated(Model $child): void
    {
        $this->stage($child, 'updated');
    }

    public function deleted(Model $child): void
    {
        $this->stage($child, 'updated');
    }

    private function stage(Model $child, string $action): void
    {
        $workOrderId = match (true) {
            $child instanceof MaintenanceLog,
            $child instanceof MaintenancePartRequest,
            $child instanceof SparePartUsage,
            $child instanceof MaintenanceWindow => $child->work_order_id,
            $child instanceof MachineDowntime => $child->maintenance_order_id,
            default => null,
        };

        if ($workOrderId === null) {
            return;
        }

        $order = MaintenanceWorkOrder::query()->find($workOrderId);
        if ($order !== null) {
            app(MaintenanceWorkOrderRealtimeService::class)->stage($order, $action);
        }

        if ($child instanceof MaintenanceWindow) {
            $previous = $child->getPrevious();
            $resources = collect([
                [$child->maintainable_type, $child->maintainable_id],
                [$previous['maintainable_type'] ?? null, $previous['maintainable_id'] ?? null],
            ])->filter(fn (array $resource): bool => in_array($resource[0], ['machine', 'mold'], true)
                && is_numeric($resource[1]) && (int) $resource[1] > 0)
                ->map(fn (array $resource): array => [(string) $resource[0], (int) $resource[1]])
                ->unique(fn (array $resource): string => $resource[0].':'.$resource[1]);

            $readiness = app(AssignedWorkOrderRefreshService::class);
            foreach ($resources as [$type, $id]) {
                if ($type === 'machine') {
                    $readiness->stageForMachine($id);
                } else {
                    $readiness->stageForMold($id);
                }
            }
        }
    }
}
