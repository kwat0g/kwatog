<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Services\OutboxService;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\AssignedWorkOrderChanged;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces linked readiness changes into one private ping per WO and transaction. */
final class AssignedWorkOrderRefreshService
{
    public function stage(WorkOrder $workOrder): void
    {
        $recipients = app(AssignedWorkOrderAudience::class)->userHashIdsFor($workOrder);

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(
            new AssignedWorkOrderChanged($recipients, (string) $workOrder->hash_id),
            "assigned-work-order-refresh:{$workOrder->getKey()}:{$transactionId}",
        );
    }

    public function stageForMachine(int $machineId): void
    {
        $this->stageForResource($machineId, 'machine_id', 'rework_machine_id');
    }

    public function stageForMold(int $moldId): void
    {
        $this->stageForResource($moldId, 'mold_id', 'rework_mold_id');
    }

    /** Refresh active work orders whose crew contains any supplied employee. */
    public function stageForEmployees(array $employeeIds): void
    {
        $employeeIds = collect($employeeIds)
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        if ($employeeIds->isEmpty()) {
            return;
        }

        WorkOrder::query()
            ->whereIn('status', [
                WorkOrderStatus::Confirmed->value,
                WorkOrderStatus::InProgress->value,
                WorkOrderStatus::Paused->value,
            ])
            ->whereHas('crews', fn ($crew) => $crew
                ->whereNull('ended_at')
                ->whereHas('members', fn ($member) => $member->whereIn('employee_id', $employeeIds)))
            ->get()
            ->each(fn (WorkOrder $workOrder) => $this->stage($workOrder));
    }

    /** Refresh crews affected by a shift definition update. */
    public function stageForShift(int $shiftId): void
    {
        $employeeIds = EmployeeShiftAssignment::query()
            ->where('shift_id', $shiftId)
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->stageForEmployees($employeeIds);
    }

    /** Refresh active readiness records whose product-level setup changed. */
    public function stageForProduct(int $productId): void
    {
        WorkOrder::query()
            ->where('product_id', $productId)
            ->whereIn('status', [
                WorkOrderStatus::Confirmed->value,
                WorkOrderStatus::InProgress->value,
                WorkOrderStatus::Paused->value,
            ])
            ->get()
            ->each(fn (WorkOrder $workOrder) => $this->stage($workOrder));
    }

    public function stageForInspection(Inspection $inspection): void
    {
        if ($inspection->stage !== InspectionStage::InProcess
            || $inspection->entity_type !== InspectionEntityType::WorkOrder
            || ! is_numeric($inspection->entity_id)) {
            return;
        }

        $workOrder = WorkOrder::query()->find((int) $inspection->entity_id);
        if ($workOrder) {
            $this->stage($workOrder);
        }
    }

    public function stageForNcr(NonConformanceReport $ncr): void
    {
        $workOrderIds = WorkOrderCrew::query()
            ->whereNull('ended_at')
            ->where('rework_ncr_id', $ncr->getKey())
            ->pluck('work_order_id');

        $inspection = $ncr->inspection_id ? Inspection::query()->find($ncr->inspection_id) : null;
        if ($inspection?->stage === InspectionStage::InProcess
            && $inspection->entity_type === InspectionEntityType::WorkOrder
            && is_numeric($inspection->entity_id)) {
            $workOrderIds->push((int) $inspection->entity_id);
        }

        $workOrderIds = $workOrderIds->filter(fn ($id) => is_numeric($id) && (int) $id > 0)->unique();
        if ($workOrderIds->isEmpty()) {
            return;
        }

        WorkOrder::query()->whereIn('id', $workOrderIds)->get()
            ->each(fn (WorkOrder $workOrder) => $this->stage($workOrder));
    }

    private function stageForResource(int $resourceId, string $workOrderColumn, string $reworkColumn): void
    {
        // Readiness on the management API is scoped to the WO, not to an
        // active crew. Equipment can change while a confirmed WO has no crew
        // assigned yet, so discover direct WO assignments independently.
        $workOrderIds = WorkOrder::query()
            ->where($workOrderColumn, $resourceId)
            ->pluck('id');

        // Rework resource assignments are owned by the NCR and can affect a
        // parent WO's readiness while an active rework crew is attached.
        $reworkWorkOrderIds = WorkOrderCrew::query()
            ->whereNull('ended_at')
            ->whereHas('ncr', fn ($ncr) => $ncr->where($reworkColumn, $resourceId))
            ->distinct()
            ->pluck('work_order_id');

        $workOrderIds = $workOrderIds->merge($reworkWorkOrderIds)->unique()->values();

        if ($workOrderIds->isEmpty()) {
            return;
        }

        WorkOrder::query()->whereIn('id', $workOrderIds)->get()
            ->each(fn (WorkOrder $workOrder) => $this->stage($workOrder));
    }
}
