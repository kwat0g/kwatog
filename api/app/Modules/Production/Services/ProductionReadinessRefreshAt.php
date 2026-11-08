<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Services\EmployeeAvailabilityService;
use App\Modules\Maintenance\Models\MaintenanceWindow;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use Illuminate\Support\Carbon;

/** Computes the next clock-only boundary for the Start Production readiness view. */
final class ProductionReadinessRefreshAt
{
    public function nextAt(
        WorkOrder $workOrder,
        ?ProductionSchedule $slot,
        ?WorkOrderCrew $crew,
        ?ProductionSchedule $executionWindow,
    ): ?Carbon {
        $now = now()->timezone(config('app.timezone'));
        $next = null;
        $consider = static function (?Carbon $boundary) use (&$next, $now): void {
            if ($boundary && $boundary->gt($now) && ($next === null || $boundary->lt($next))) {
                $next = $boundary->copy();
            }
        };

        // Clock-only changes in executionWindow(): early/scheduled/late mode,
        // the start grace period, remaining slot duration, and slot expiry.
        if ($slot) {
            $consider($slot->scheduled_start);
            $consider($slot->scheduled_start->copy()->addMinutes(ProductionResourceService::START_GRACE_MINUTES)->addSecond());
            if ($workOrder->mold) {
                try {
                    $required = app(ProductionResourceService::class)->durationMinutes($workOrder, $workOrder->mold);
                    $consider($slot->scheduled_end->copy()->subMinutes($required)->addSecond());
                } catch (BusinessRuleException) {
                    // Readiness already reports the missing/invalid mold capacity.
                }
            }
            $consider($slot->scheduled_end);
        }

        // Calendar-based leave and assignment rules are reevaluated at the
        // next plant-local day. This advances the employee shift lookup horizon.
        $consider($now->copy()->addDay()->startOfDay());

        foreach ($crew?->members ?? [] as $member) {
            if ($member->employee) {
                $consider(app(EmployeeAvailabilityService::class)->nextProductionBoundary($member->employee, $now));
            }
        }

        $windowSeconds = $this->candidateWindowSeconds($workOrder, $slot, $executionWindow, $now);
        $resourceSchedules = ($workOrder->machine_id || $workOrder->mold_id)
            ? ProductionSchedule::query()
                ->where('reserves_capacity', true)
                ->where(fn ($query) => $query->where('work_order_id', '!=', $workOrder->id)->orWhereNotNull('rework_ncr_id'))
                ->where('scheduled_end', '>', $now)
                ->where(function ($query) use ($workOrder) {
                    if ($workOrder->machine_id) {
                        $query->where('machine_id', $workOrder->machine_id);
                    }
                    if ($workOrder->mold_id) {
                        $workOrder->machine_id
                            ? $query->orWhere('mold_id', $workOrder->mold_id)
                            : $query->where('mold_id', $workOrder->mold_id);
                    }
                })
                ->get(['scheduled_start', 'scheduled_end'])
            : collect();

        foreach ($resourceSchedules as $reservation) {
            $this->considerInterval($consider, $reservation->scheduled_start, $reservation->scheduled_end, $windowSeconds);
        }

        if ($workOrder->machine_id || $workOrder->mold_id) {
            $maintenance = MaintenanceWindow::confirmed()
                ->where(fn ($query) => $query
                    ->where(fn ($query) => $query->where('maintainable_type', 'machine')->where('maintainable_id', $workOrder->machine_id))
                    ->orWhere(fn ($query) => $query->where('maintainable_type', 'mold')->where('maintainable_id', $workOrder->mold_id)))
                ->where('planned_end_at', '>', $now)
                ->get(['planned_start_at', 'planned_end_at']);

            foreach ($maintenance as $reservation) {
                $this->considerInterval($consider, $reservation->planned_start_at, $reservation->planned_end_at, $windowSeconds);
            }
        }

        // An employee's other active reservation can become overdue while this
        // dialog stays open. Scope the lookup to its assigned crew, never all WOs.
        $employeeIds = $crew?->members->pluck('employee_id')->filter()->unique()->values()->all() ?? [];
        if ($employeeIds !== []) {
            $otherCrews = WorkOrderCrew::query()
                ->whereNull('ended_at')
                ->where('id', '!=', $crew->id)
                ->whereHas('members', fn ($query) => $query->whereIn('employee_id', $employeeIds))
                ->get();

            foreach ($otherCrews as $otherCrew) {
                $otherSlot = ProductionSchedule::query()
                    ->where('work_order_id', $otherCrew->work_order_id)
                    ->where('rework_ncr_id', $otherCrew->rework_ncr_id)
                    ->where('reserves_capacity', true)
                    ->whereIn('status', ['pending', 'confirmed', 'executed'])
                    ->latest('id')
                    ->first();
                if ($otherSlot) {
                    $this->considerInterval($consider, $otherSlot->scheduled_start, $otherSlot->scheduled_end, $windowSeconds);
                }
            }
        }

        return $next;
    }

    private function candidateWindowSeconds(
        WorkOrder $workOrder,
        ?ProductionSchedule $slot,
        ?ProductionSchedule $executionWindow,
        Carbon $now,
    ): int {
        if ($executionWindow) {
            return max(0, (int) ceil($now->diffInSeconds($executionWindow->scheduled_end, false)));
        }

        if (! $workOrder->mold) {
            return 0;
        }

        try {
            $resources = app(ProductionResourceService::class);
            $minutes = $slot && $now->lt($slot->scheduled_start)
                ? $resources->reservationMinutes($workOrder, $workOrder->mold)
                : $resources->durationMinutes($workOrder, $workOrder->mold) + 15;

            return max(0, $minutes * 60);
        } catch (BusinessRuleException) {
            return 0;
        }
    }

    /** @param callable(?Carbon): void $consider */
    private function considerInterval(callable $consider, Carbon $start, Carbon $end, int $windowSeconds): void
    {
        $consider($start);
        $consider($end);
        if ($windowSeconds > 0) {
            // Overlap starts when the sliding candidate interval reaches a
            // future reservation. Add one second for the strict overlap check.
            $consider($start->copy()->subSeconds($windowSeconds)->addSecond());
        }
    }
}
