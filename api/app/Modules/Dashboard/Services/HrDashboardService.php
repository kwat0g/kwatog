<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\Concerns\DashboardQueries;
use App\Modules\Dashboard\Services\ForecastingDashboardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4.1 extraction — HR Officer + Employee Self-Service dashboards.
 * Grouped together because both are HR-domain; employee is a subset of HR data.
 * Owns: hr, employee, all hr* helpers, headcount/hire/leave helpers, payslip, holiday.
 */
class HrDashboardService
{
    use DashboardQueries;

    private const CACHE_TTL = 30;

    public function __construct(
        private readonly ForecastingDashboardService $forecastingService,
    ) {}

    public function hr(User $user): array
    {
        return Cache::remember("dashboard:hr:{$user->id}", self::CACHE_TTL, function () use ($user) {
            $headcount         = $this->safeCount('employees', fn ($q) => $q->where('status', 'active'));
            $onLeaveToday      = $this->safeCount('leave_requests', fn ($q) => $q
                ->where('status', 'approved')
                ->where('start_date', '<=', today())
                ->where('end_date', '>=', today()));
            $pendingLeave      = $this->safeCount('leave_requests', fn ($q) => $q->whereIn('status', ['pending_dept', 'pending_hr', 'pending']));
            $pendingSeparation = $this->safeCount('clearances',     fn ($q) => $q->whereIn('status', ['pending', 'in_progress', 'completed']));

            return [
                'kpis' => [
                    $this->kpi('Active Headcount', (string) $headcount,        'count'),
                    $this->kpi('On Leave Today',   (string) $onLeaveToday,     'count'),
                    $this->kpi('Pending Leave',    (string) $pendingLeave,     'count'),
                    $this->kpi('Open Clearances',  (string) $pendingSeparation, 'count'),
                ],
                'panels' => [
                    'by_department'      => $this->headcountByDepartment(),
                    'recent_hires'       => $this->recentHires(),
                    'pending_leaves'     => $this->pendingLeaves(),
                    'attendance_summary' => $this->hrAttendanceSummary(),
                    'probation_alerts'   => $this->hrProbationAlerts(),
                    'leave_calendar_week'=> $this->hrLeaveCalendarWeek(),
                    'hr_calendar_events' => $this->hrCalendarEvents(),
                    'pending_my_action'  => $this->hrPendingMyAction($user),
                    'headcount_forecast' => $this->forecastingService->headcountForecast(),
                ],
            ];
        });
    }

    public function employee(User $user): array
    {
        if (! $user->employee_id) {
            return ['kpis' => [], 'panels' => ['notice' => 'No linked employee profile.']];
        }
        return Cache::remember("dashboard:employee:{$user->id}", self::CACHE_TTL, function () use ($user) {
            $myAttendance = $this->safeCount('attendances', fn ($q) => $q
                ->where('employee_id', $user->employee_id)
                ->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()]));
            $myLeaveRem   = $this->safeSum('employee_leave_balances', 'remaining', fn ($q) => $q
                ->where('employee_id', $user->employee_id)
                ->where('year', (int) now()->format('Y')));
            $myPending    = $this->safeCount('leave_requests', fn ($q) => $q
                ->where('employee_id', $user->employee_id)
                ->whereIn('status', ['pending_dept', 'pending_hr', 'pending']));

            return [
                'kpis' => [
                    $this->kpi('Attendance · Month',   (string) $myAttendance, 'days'),
                    $this->kpi('Leave Days Remaining', $myLeaveRem,            'days'),
                    $this->kpi('Pending Requests',     (string) $myPending,    'count'),
                ],
                'panels' => [
                    'latest_payslip' => $this->latestPayslip($user->employee_id),
                    'next_holiday'   => $this->nextHoliday(),
                ],
            ];
        });
    }

    /* ─── Task D4 — HR Officer helper methods ─── */

    /**
     * @return array{present: int, late: int, absent: int, on_leave: int}
     */
    private function hrAttendanceSummary(): array
    {
        $today = today()->toDateString();
        if (! Schema::hasTable('attendances') || ! Schema::hasTable('employees')) {
            return ['present' => 0, 'late' => 0, 'absent' => 0, 'on_leave' => 0];
        }

        $activeEmployees = (int) DB::table('employees')->where('status', 'active')->count();
        if ($activeEmployees === 0) {
            return ['present' => 0, 'late' => 0, 'absent' => 0, 'on_leave' => 0];
        }

        $present = (int) DB::table('attendances')
            ->whereDate('date', $today)
            ->whereNotIn('status', ['absent'])
            ->where('tardiness_minutes', 0)
            ->count();

        $late = (int) DB::table('attendances')
            ->whereDate('date', $today)
            ->where('tardiness_minutes', '>', 0)
            ->count();

        $onLeave = (int) DB::table('leave_requests')
            ->where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->count();

        $absent = max(0, $activeEmployees - $present - $late - $onLeave);

        return [
            'present'  => $present,
            'late'     => $late,
            'absent'   => $absent,
            'on_leave' => $onLeave,
        ];
    }

    /**
     * @return array<int, array{id: string, employee_no: string, name: string, date_hired: string, probation_end: string, department: string}>
     */
    private function hrProbationAlerts(): array
    {
        if (! Schema::hasTable('employees')) return [];

        $thirtyDays = now()->addDays(30)->toDateString();
        $today = today()->toDateString();

        return DB::table('employees')
            ->leftJoin('departments', 'employees.department_id', '=', 'departments.id')
            ->where('employees.status', 'active')
            ->where('employees.employment_type', 'probationary')
            ->whereNull('employees.date_regularized')
            ->whereBetween('employees.date_hired', [
                now()->subMonths(6)->toDateString(),
                now()->addDays(30)->subMonths(6)->toDateString(),
            ])
            ->select(
                'employees.id',
                'employees.employee_no',
                'employees.first_name',
                'employees.last_name',
                'employees.date_hired',
                'departments.name as department_name'
            )
            ->orderBy('employees.date_hired')
            ->limit(10)
            ->get()
            ->map(fn ($e) => [
                'id'            => app('hashids')->encode((int) $e->id),
                'employee_no'   => $e->employee_no,
                'name'          => trim(($e->first_name ?? '').' '.($e->last_name ?? '')),
                'date_hired'    => $e->date_hired,
                'probation_end' => Carbon::parse((string) $e->date_hired)->addMonths(6)->toDateString(),
                'department'    => $e->department_name ?? '—',
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: string, employee_no: string, name: string, start_date: string, end_date: string, days: string}>
     */
    private function hrLeaveCalendarWeek(): array
    {
        if (! Schema::hasTable('leave_requests')) return [];

        $today   = today()->toDateString();
        $weekEnd = now()->addDays(7)->toDateString();

        return DB::table('leave_requests')
            ->join('employees', 'leave_requests.employee_id', '=', 'employees.id')
            ->where('leave_requests.status', 'approved')
            ->where('leave_requests.start_date', '<=', $weekEnd)
            ->where('leave_requests.end_date', '>=', $today)
            ->orderBy('leave_requests.start_date')
            ->limit(8)
            ->select(
                'leave_requests.id',
                'leave_requests.start_date',
                'leave_requests.end_date',
                'leave_requests.days',
                'employees.employee_no',
                'employees.first_name',
                'employees.last_name'
            )
            ->get()
            ->map(fn ($r) => [
                'id'          => app('hashids')->encode((int) $r->id),
                'employee_no' => $r->employee_no,
                'name'        => trim(($r->first_name ?? '').' '.($r->last_name ?? '')),
                'start_date'  => $r->start_date,
                'end_date'    => $r->end_date,
                'days'        => (string) ($r->days ?? '0'),
            ])
            ->all();
    }

    /**
     * @return array{holidays: array<int, array{name: string, date: string, type: string}>, birthdays: array<int, array{id: string, name: string, date: string}>, birthdays_count: int}
     */
    private function hrCalendarEvents(): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd   = now()->endOfMonth()->toDateString();

        $holidays = [];
        if (Schema::hasTable('holidays')) {
            $holidays = DB::table('holidays')
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->orderBy('date')
                ->get(['id', 'name', 'date', 'type'])
                ->map(fn ($h) => [
                    'name' => $h->name,
                    'date' => $h->date,
                    'type' => $h->type,
                ])
                ->all();
        }

        $birthdays = [];
        if (Schema::hasTable('employees')) {
            $monthNum  = (int) now()->format('n');
            $birthdays = DB::table('employees')
                ->where('status', 'active')
                ->whereMonth('birth_date', $monthNum)
                ->limit(10)
                ->get(['id', 'first_name', 'last_name', 'birth_date'])
                ->sortBy(fn ($e) => (int) Carbon::parse((string) $e->birth_date)->format('j'))
                ->values()
                ->map(fn ($e) => [
                    'id'   => app('hashids')->encode((int) $e->id),
                    'name' => trim(($e->first_name ?? '').' '.($e->last_name ?? '')),
                    'date' => $e->birth_date,
                ])
                ->all();
        }

        return [
            'holidays'        => $holidays,
            'birthdays'       => $birthdays,
            'birthdays_count' => count($birthdays),
        ];
    }

    /**
     * @return array{leave_requests: int, profile_updates: int, clearances: int, total: int}
     */
    private function hrPendingMyAction(User $user): array
    {
        $leavePending = $this->safeCount('leave_requests', fn ($q) => $q->where('status', 'pending_hr'));
        if (Schema::hasTable('profile_update_requests')) {
            $profilePending = (int) DB::table('profile_update_requests')->where('status', 'pending')->count();
        } else {
            $profilePending = 0;
        }
        $clearancesPending = $this->safeCount('clearances', fn ($q) => $q->where('status', 'pending'));

        $total = $leavePending + $profilePending + $clearancesPending;

        return [
            'leave_requests'  => $leavePending,
            'profile_updates' => $profilePending,
            'clearances'      => $clearancesPending,
            'total'           => $total,
        ];
    }

    /* ─── Headcount / HR list helpers ─── */

    private function headcountByDepartment(): array
    {
        if (! Schema::hasTable('employees') || ! Schema::hasTable('departments')) return [];
        return DB::table('employees')
            ->leftJoin('departments', 'employees.department_id', '=', 'departments.id')
            ->where('employees.status', 'active')
            ->select('departments.name as label', DB::raw('COUNT(*) as count'))
            ->groupBy('departments.id', 'departments.name')
            ->orderByDesc('count')->get()
            ->map(fn ($r) => ['label' => $r->label ?? '—', 'count' => (int) $r->count])->all();
    }

    private function recentHires(): array
    {
        if (! Schema::hasTable('employees')) return [];
        return DB::table('employees')
            ->where('status', 'active')->orderByDesc('date_hired')
            ->select('id', 'employee_no', 'first_name', 'last_name', 'date_hired')->limit(5)->get()
            ->map(fn ($e) => [
                'id'          => app('hashids')->encode((int) $e->id),
                'employee_no' => $e->employee_no,
                'name'        => trim(($e->first_name ?? '').' '.($e->last_name ?? '')),
                'date_hired'  => $e->date_hired,
            ])->all();
    }

    private function pendingLeaves(): array
    {
        if (! Schema::hasTable('leave_requests')) return [];
        return DB::table('leave_requests')
            ->whereIn('status', ['pending_dept', 'pending_hr', 'pending'])
            ->orderByDesc('id')->limit(8)->get()
            ->map(fn ($r) => [
                'id'               => app('hashids')->encode((int) $r->id),
                'leave_request_no' => $r->leave_request_no ?? null,
                'status'           => $r->status,
                'days'             => (string) ($r->days ?? '0'),
            ])->all();
    }

    /* ─── Employee self-service helpers ─── */

    private function latestPayslip(int $employeeId): ?array
    {
        if (! Schema::hasTable('payrolls')) return null;
        $row = DB::table('payrolls')
            ->where('employee_id', $employeeId)
            ->orderByDesc('id')->first();
        if (! $row) return null;
        return [
            'id'        => app('hashids')->encode((int) $row->id),
            'gross_pay' => (string) ($row->gross_pay ?? '0'),
            'net_pay'   => (string) ($row->net_pay ?? '0'),
        ];
    }

    private function nextHoliday(): ?array
    {
        if (! Schema::hasTable('holidays')) return null;
        $row = DB::table('holidays')->where('date', '>=', today())->orderBy('date')->first();
        if (! $row) return null;
        return ['name' => $row->name, 'date' => $row->date, 'type' => $row->type];
    }
}
