<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Models\MaintenanceWindow;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceWindowService
{
    public function suggestions(MaintenanceSchedule $schedule, MaintenanceAssigneeSuggestionService $assignees): array
    {
        $order = MaintenanceWorkOrder::query()->where('schedule_id', $schedule->id)
            ->whereNotIn('status', ['completed', 'cancelled'])->latest('id')->first();
        $result = ['work_order_id' => $order?->hash_id, 'confirmed_window' => null,
            'suggestions' => [], 'blockers' => ['schedule' => [], 'production' => [], 'maintenance' => [], 'staffing' => []]];
        if (! $schedule->is_active) {
            $result['blockers']['schedule'][] = 'Schedule is disabled.';
            return $result;
        }
        if (! $order) {
            $result['blockers']['schedule'][] = 'No active preventive work order exists for this schedule.';
            return $result;
        }
        $confirmed = MaintenanceWindow::confirmed()->where('work_order_id', $order->id)->first();
        if ($confirmed) {
            $result['confirmed_window'] = ['planned_start_at' => $confirmed->planned_start_at->toIso8601String(),
                'planned_end_at' => $confirmed->planned_end_at->toIso8601String(),
                'technician' => $order->assignee ? ['id' => $order->assignee->hash_id,
                    'name' => trim($order->assignee->first_name.' '.$order->assignee->last_name)] : null];
            return $result;
        }
        if ($order->status->value !== 'open') {
            $result['blockers']['schedule'][] = 'Only an open preventive work order can reserve a window.';
            return $result;
        }

        $now = now()->timezone(config('app.timezone'));
        $limit = $now->copy()->addDays(7);
        $start = $now->copy()->addMinutes(15 - $now->minute % 15)->startOfMinute();
        $duration = $schedule->estimated_duration_minutes;
        $type = $schedule->maintainable_type->value;
        $production = ProductionSchedule::query()->where($type.'_id', $schedule->maintainable_id)
            ->where('reserves_capacity', true)->whereIn('status', ['pending', 'confirmed', 'executed'])
            ->where('scheduled_start', '<', $limit)->where('scheduled_end', '>', $now)
            ->orderBy('scheduled_start')->get();
        $maintenance = MaintenanceWindow::confirmed()->where('maintainable_type', $type)
            ->where('maintainable_id', $schedule->maintainable_id)->where('work_order_id', '!=', $order->id)
            ->where('planned_start_at', '<', $limit)->where('planned_end_at', '>', $now)
            ->orderBy('planned_start_at')->get();

        while ($start->copy()->addMinutes($duration)->lte($limit) && count($result['suggestions']) < 3) {
            $end = $start->copy()->addMinutes($duration);
            $booked = $production->first(fn ($row) => $row->scheduled_start->lt($end) && $row->scheduled_end->gt($start));
            if ($booked) {
                $result['blockers']['production'] = ['Production reserves this equipment during the search period.'];
            } else {
                $booked = $maintenance->first(fn ($row) => $row->planned_start_at->lt($end) && $row->planned_end_at->gt($start));
                if ($booked) {
                    $result['blockers']['maintenance'] = ['Another confirmed maintenance window reserves this equipment during the search period.'];
                } else {
                    $candidate = $assignees->suggest($order, $start, $end);
                    $technician = collect($candidate['candidates'])->firstWhere('id', $candidate['suggested_employee_id']);
                    if ($technician) {
                        $result['suggestions'][] = ['planned_start_at' => $start->toIso8601String(),
                            'planned_end_at' => $end->toIso8601String(),
                            'technician' => ['id' => $technician['id'], 'name' => $technician['name'],
                                'employee_no' => $technician['employee_no']]];
                        $start->addMinutes((int) (ceil($duration / 15) * 15));
                        continue;
                    }
                    $reasons = collect($candidate['candidates'])->pluck('blocked_reason')->filter()->unique()->values()->all();
                    $result['blockers']['staffing'] = $reasons ?: ['No eligible maintenance technician covers an unreserved interval.'];
                }
            }
            $start->addMinutes(15);
        }

        return $result;
    }

    public function confirm(MaintenanceWorkOrder $order, Carbon $start, Carbon $end, User $actor): MaintenanceWindow
    {
        abort_unless($actor->is_active && $actor->role?->slug === 'maintenance_supervisor'
            && $actor->hasPermission('maintenance.schedules.manage'), 403);

        $start = $start->copy()->timezone(config('app.timezone'));
        $end = $end->copy()->timezone(config('app.timezone'));

        return DB::transaction(function () use ($order, $start, $end, $actor): MaintenanceWindow {
            $order = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->type->value !== 'preventive' || $order->status->value !== 'open') {
                throw ValidationException::withMessages(['status' => ['Only open preventive work orders can reserve a window.']]);
            }
            if ($start->lt(now()) || ! $start->lt($end) || $start->diffInMinutes($end, true) < 15 || $start->diffInMinutes($end, true) > 480) {
                throw ValidationException::withMessages(['planned_start_at' => ['Choose a future interval lasting 15–480 minutes.']]);
            }
            $type = $order->maintainable_type->value;
            ($type === 'machine' ? Machine::query() : Mold::query())->lockForUpdate()->findOrFail($order->maintainable_id);
            $this->assertAvailable($order, $start, $end);
            MaintenanceWindow::confirmed()->where('work_order_id', $order->id)->update(['state' => 'released']);

            return MaintenanceWindow::create([
                'maintainable_type' => $type, 'maintainable_id' => $order->maintainable_id,
                'work_order_id' => $order->id, 'planned_start_at' => $start, 'planned_end_at' => $end,
                'state' => 'confirmed', 'confirmed_by' => $actor->id, 'confirmed_at' => now(),
            ]);
        });
    }

    /** Call under the target equipment lock when confirming or starting work. */
    public function assertAvailable(MaintenanceWorkOrder $order, Carbon $start, Carbon $end): void
    {
        $type = $order->maintainable_type->value;
        $production = ProductionSchedule::query()->where($type.'_id', $order->maintainable_id)
            ->where('reserves_capacity', true)->whereIn('status', ['pending', 'confirmed', 'executed'])
            ->where('scheduled_start', '<', $end)->where('scheduled_end', '>', $start)->exists();
        $maintenance = MaintenanceWindow::confirmed()->where('maintainable_type', $type)
            ->where('maintainable_id', $order->maintainable_id)->where('work_order_id', '!=', $order->id)
            ->overlapping($start, $end)->exists();
        if ($production || $maintenance) {
            throw ValidationException::withMessages(['planned_start_at' => ['Equipment is already reserved for this interval.']]);
        }
    }

    public function release(MaintenanceWorkOrder $order): void
    {
        MaintenanceWindow::confirmed()->where('work_order_id', $order->id)->update(['state' => 'released']);
    }
}
