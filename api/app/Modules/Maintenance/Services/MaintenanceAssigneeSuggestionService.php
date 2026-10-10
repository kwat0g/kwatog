<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Modules\Attendance\Services\EmployeeAvailabilityService;
use App\Modules\HR\Models\Employee;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use Illuminate\Support\Carbon;

class MaintenanceAssigneeSuggestionService
{
    public function __construct(private readonly EmployeeAvailabilityService $availability) {}

    public function candidate(MaintenanceWorkOrder $order, Employee $employee, Carbon $start, Carbon $end): array
    {
        $employee->loadMissing('department', 'user.role');
        $future = $start->gt(now()->addMinute());
        $presence = $future
            ? ['state' => 'unknown', 'reason_code' => 'future', 'reason' => 'Future attendance must be checked when work starts.']
            : $this->availability->presence($employee, $start);
        $load = MaintenanceWorkOrder::where('assigned_to', $employee->id)
            ->where('id', '!=', $order->id)->whereIn('status', ['assigned', 'in_progress'])->count();
        $busy = MaintenanceWorkOrder::where('assigned_to', $employee->id)
            ->where('id', '!=', $order->id)->where('status', 'in_progress')->exists();
        $blocker = match (true) {
            $employee->status?->value !== 'active' => 'Employee is inactive.',
            $employee->department?->code !== 'MAINT' || ! $employee->department?->is_active => 'Employee is not in an active Maintenance department.',
            ! $employee->user?->is_active || ! in_array($employee->user?->role?->slug, ['maintenance_tech', 'employee'], true) => 'Active Maintenance employee or technician account required.',
            $busy => 'Technician is already working on another maintenance order.',
            default => $this->availability->coverageBlocker($employee, $start, $end),
        };
        $attestable = ! $future && ! $blocker && $presence['reason_code'] === 'unverified';
        $canAssign = ! $blocker && ($future || $presence['state'] === 'present');
        if (! $blocker && ! $canAssign) {
            $blocker = $presence['reason'];
        }

        return ['id' => $employee->hash_id, 'employee_id' => $employee->id,
            'employee_no' => $employee->employee_no, 'name' => trim($employee->first_name.' '.$employee->last_name),
            'presence' => $presence, 'active_load' => $load, 'can_assign' => $canAssign,
            'can_attest_presence' => $attestable, 'blocked_reason' => $blocker];
    }

    public function suggest(MaintenanceWorkOrder $order, Carbon $start, Carbon $end): array
    {
        $candidates = Employee::with('department', 'user.role')
            ->whereHas('department', fn ($query) => $query->where('code', 'MAINT'))
            ->get()->map(fn ($employee) => $this->candidate($order, $employee, $start, $end))
            ->sortBy(fn ($row) => [! $row['can_assign'], ! $row['can_attest_presence'], $row['active_load'], $row['employee_no']])->values();
        $suggested = $candidates->firstWhere('can_assign', true);

        return ['suggested_employee_id' => $suggested['id'] ?? null,
            'evaluated_at' => now()->toIso8601String(),
            'candidates' => $candidates->map(fn ($row) => array_diff_key($row, ['employee_id' => true]))->all()];
    }
}
