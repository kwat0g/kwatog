<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Support;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use Illuminate\Database\Eloquent\Builder;

final class MaintenanceWorkAccess
{
    public static function canRecord(MaintenanceWorkOrder $order, ?User $user): bool
    {
        if (! $user?->is_active || $order->status !== MaintenanceWorkOrderStatus::InProgress
            || $order->repair_submitted_at !== null) {
            return false;
        }
        if ($user->role?->slug === 'system_admin') {
            return $user->can('maintenance.wo.complete');
        }
        if ($user->employee_id === null || (int) $order->assigned_to !== (int) $user->employee_id) {
            return false;
        }

        if ($user->role?->slug === 'employee') {
            return $user->can('maintenance.work.assigned') && Employee::query()->whereKey($user->employee_id)
                ->where('status', 'active')->whereHas('department', fn (Builder $department) => $department
                ->where('code', 'MAINT')->where('is_active', true))->exists();
        }

        return $user->can('maintenance.wo.complete');
    }

    public static function scopeReadable(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->where('assigned_to', $user->employee_id ?? 0)
                ->orWhereHas('logs', fn (Builder $logs) => $logs->where('logged_by', $user->id)->whereNotNull('finding'));
        });
    }

    public static function canRead(MaintenanceWorkOrder $order, User $user): bool
    {
        return $user->is_active && $user->can('maintenance.work.assigned')
            && self::scopeReadable(MaintenanceWorkOrder::query()->whereKey($order->id), $user)->exists();
    }

    public static function hasEmployeeAssignee(MaintenanceWorkOrder $order): bool
    {
        return $order->assigned_to !== null && $order->assignee?->user?->role?->slug === 'employee';
    }

    public static function supervisorMayManage(MaintenanceWorkOrder $order, ?User $user): bool
    {
        return $user?->is_active && $user->role?->slug === 'maintenance_supervisor'
            && $user->can('maintenance.wo.assign') && self::hasEmployeeAssignee($order);
    }
}
