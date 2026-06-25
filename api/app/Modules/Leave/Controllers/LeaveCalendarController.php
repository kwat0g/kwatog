<?php

declare(strict_types=1);

namespace App\Modules\Leave\Controllers;

use App\Common\Support\HashIdFilter;
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
    public function index(Request $request): JsonResponse
    {
        $year  = $request->integer('year', now()->year);
        $month = $request->integer('month', now()->month);
        $deptId = $request->filled('department_id')
            ? HashIdFilter::decode($request->input('department_id'), Department::class)
            : null;

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = $start->copy()->endOfMonth();

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
            $onLeave = $leaves->filter(fn ($l) =>
                $l->start_date->lte($day) && $l->end_date->gte($day)
            );
            $approvedCount = $onLeave->where('status', LeaveRequestStatus::Approved)->count();
            $pendingCount  = $onLeave->filter(fn ($l) =>
                $l->status === LeaveRequestStatus::PendingDept || $l->status === LeaveRequestStatus::PendingHr
            )->count();
            $present       = max(0, $headcount - $approvedCount);
            $coverage      = $headcount > 0 ? round($present / $headcount * 100, 1) : 100;

            $days[] = [
                'date'                => $dateStr,
                'day_of_week'         => $day->dayOfWeek,
                'approved_count'      => $approvedCount,
                'pending_count'       => $pendingCount,
                'present_count'       => $present,
                'headcount'           => $headcount,
                'coverage_pct'        => $coverage,
                'employees_on_leave'  => $onLeave->map(fn ($l) => [
                    'employee_name' => $l->employee?->full_name ?? '',
                    'status'        => $l->status instanceof \BackedEnum ? $l->status->value : (string) $l->status,
                    'leave_type'    => $l->leaveType?->name ?? '',
                ])->values()->toArray(),
            ];
        }

        return response()->json([
            'data' => [
                'year'      => $year,
                'month'     => $month,
                'headcount' => $headcount,
                'days'      => $days,
            ],
        ]);
    }
}
