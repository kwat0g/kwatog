<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Production\Enums\ProductionScheduleStatus;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;

/** Read-only equipment handoff evidence; Start rechecks transactional guards. */
class MaintenanceReadinessService
{
    public function forOrder(MaintenanceWorkOrder $order, ?User $actor): array
    {
        $target = $order->maintainable();
        $status = $target?->status?->value;
        $blockers = [];
        $production = null;
        if (! $target || $target->trashed()) {
            $blockers[] = [
                'code' => 'equipment_unavailable',
                'message' => 'The equipment is missing or archived.',
                'required_action' => 'Maintenance supervision must review the equipment target.',
            ];
        } else {
            if ($target->current_work_order_id !== null || in_array($status, ['running', 'in_use'], true)) {
                $production = WorkOrder::withTrashed()->find($target->current_work_order_id);
                $blockers[] = [
                    'code' => 'production_ownership',
                    'message' => 'Production still owns this equipment'.($production ? ' for '.$production->wo_number : '').'.',
                    'required_action' => 'Production must pause the work order and use Release resources before maintenance starts.',
                ];
            }
            if ($production === null) {
                $activeOrder = WorkOrder::query()
                    ->where($order->maintainable_type === MaintainableType::Machine ? 'machine_id' : 'mold_id', $target->id)
                    ->where('status', 'in_progress')->first();
                if ($activeOrder) {
                    $production = $activeOrder;
                    $blockers[] = [
                        'code' => 'production_ownership',
                        'message' => 'Production still owns this equipment for '.$activeOrder->wo_number.'.',
                        'required_action' => 'Production must pause the work order and use Release resources before maintenance starts.',
                    ];
                }
            }
            $at = now();
            $reservation = ProductionSchedule::query()
                ->where($order->maintainable_type === MaintainableType::Machine ? 'machine_id' : 'mold_id', $target->id)
                ->where('reserves_capacity', true)
                ->whereIn('status', [ProductionScheduleStatus::Pending->value, ProductionScheduleStatus::Confirmed->value, ProductionScheduleStatus::Executed->value])
                ->where('scheduled_start', '<=', $at)
                ->where('scheduled_end', '>', $at)
                ->orderBy('scheduled_start')->orderBy('id')->first();
            if ($reservation) {
                $reservedOrder = WorkOrder::withTrashed()->find($reservation->work_order_id);
                $production ??= $reservedOrder;
                $blockers[] = [
                    'code' => 'production_reservation',
                    'message' => 'Production currently reserves this equipment'.($reservedOrder ? ' for '.$reservedOrder->wo_number : '').'.',
                    'required_action' => 'Production must review and release or reschedule the active reservation before maintenance starts.',
                ];
            }
            if (MaintenanceWorkOrder::query()
                ->where('maintainable_type', $order->maintainable_type->value)
                ->where('maintainable_id', $order->maintainable_id)
                ->whereKeyNot($order->getKey())
                ->where('status', MaintenanceWorkOrderStatus::InProgress->value)->exists()) {
                $blockers[] = [
                    'code' => 'maintenance_in_progress',
                    'message' => 'Another maintenance work order is already in progress for this equipment.',
                    'required_action' => 'Maintenance supervision must finish or cancel the existing maintenance work order.',
                ];
            }
            if ($status === 'retired') {
                $blockers[] = [
                    'code' => 'equipment_unavailable',
                    'message' => 'Retired equipment cannot enter maintenance.',
                    'required_action' => 'Maintenance supervision must review the equipment target.',
                ];
            }
        }

        return [
            'equipment_status' => $status,
            'can_start' => $blockers === [] && in_array($order->status, [MaintenanceWorkOrderStatus::Open, MaintenanceWorkOrderStatus::Assigned], true),
            'production_work_order' => $production ? [
                'id' => $production->hash_id,
                'wo_number' => $production->wo_number,
                'status' => $production->status->value,
                'link' => $actor?->hasPermission('production.view') && $actor->hasPermission('production.work_orders.view')
                    ? '/production/work-orders/'.$production->hash_id : null,
            ] : null,
            'blockers' => $blockers,
        ];
    }
}
