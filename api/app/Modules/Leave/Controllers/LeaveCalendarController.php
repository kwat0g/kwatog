<?php

declare(strict_types=1);

namespace App\Modules\Leave\Controllers;

use App\Common\Support\HashIdFilter;
use App\Common\Services\SettingsService;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveCalendarController
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index(Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);
        $month = $request->integer('month', now()->month);
        $deptId = $request->filled('department_id')
            ? HashIdFilter::decode($request->input('department_id'), Department::class)
            : null;

        $user = $request->user();
        // hasPermission short-circuits for system_admin, so the removed
        // `role->slug !== 'system_admin'` term could never change the outcome.
        if (! $user?->hasPermission('leave.approve_hr')) {
            $deptId = Employee::query()->whereKey($user?->employee_id)->value('department_id');
            abort_unless($deptId, 403, 'Your account is not assigned to a department.');
        }

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $headcount = Employee::query()
            ->where('status', 'active')
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->count();

        $leaves = LeaveRequest::query()
            ->whereIn('status', ['approved', 'pending_dept', 'pending_hr'])
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->when($deptId, fn ($q) => $q->whereHas('employee', fn ($eq) => $eq->where('department_id', $deptId)))
            ->with(['employee:id,first_name,last_name,department_id', 'leaveType:id,name,code'])
            ->get();

        $days = [];
        foreach (CarbonPeriod::create($start, $end) as $day) {
            $dateStr = $day->toDateString();
            $onLeave = $leaves->filter(fn ($l) => $l->start_date->lte($day) && $l->end_date->gte($day)
            );
            $approvedCount = $onLeave->where('status', LeaveRequestStatus::Approved)->count();
            $pendingCount = $onLeave->filter(fn ($l) => $l->status === LeaveRequestStatus::PendingDept || $l->status === LeaveRequestStatus::PendingHr
            )->count();
            $present = max(0, $headcount - $approvedCount);
            $coverage = $headcount > 0 ? round($present / $headcount * 100, 1) : 100;

            $days[] = [
                'date' => $dateStr,
                'day_of_week' => $day->dayOfWeek,
                'approved_count' => $approvedCount,
                'pending_count' => $pendingCount,
                'present_count' => $present,
                'headcount' => $headcount,
                'coverage_pct' => $coverage,
                'employees_on_leave' => $onLeave->map(fn ($l) => [
                    'employee_name' => $l->employee?->full_name ?? '',
                    'status' => $l->status instanceof \BackedEnum ? $l->status->value : (string) $l->status,
                    'status_label' => $l->status?->label() ?? (string) $l->status,
                    'leave_type' => $l->leaveType?->name ?? '',
                    // M-18 — half-day marker ('am'|'pm') so the calendar
                    // tooltip distinguishes half-day leaves from full-day.
                    'half_day_period' => $l->half_day_period?->value ?? null,
                ])->values()->toArray(),
            ];
        }

        return response()->json([
            'data' => [
                'year' => $year,
                'month' => $month,
                'headcount' => $headcount,
                'coverage_policy' => [
                    'success_pct' => $this->settings->requiredFloat('leave.calendar.coverage_success_pct', 0, 100),
                    'warning_pct' => $this->settings->requiredFloat('leave.calendar.coverage_warning_pct', 0, 100),
                ],
                'days' => $days,
            ],
        ]);
    }
}
