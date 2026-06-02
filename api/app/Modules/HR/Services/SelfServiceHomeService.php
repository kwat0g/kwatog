<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4.4 — Self-service home dashboard assembly. Extracted verbatim from
 * SelfServiceController::home() to keep the controller thin (CLAUDE.md:
 * controllers delegate to services). Behavior-preserving: same queries,
 * same Schema::hasTable guards, same array keys.
 */
class SelfServiceHomeService
{
    /**
     * @return array{todays_shift: ?array, leave_balances: array, pending_count: int, latest_payslip: ?array}
     */
    public function summary(Employee $employee): array
    {
        return [
            'todays_shift'   => $this->todaysShift($employee),
            'leave_balances' => $this->leaveBalances($employee),
            'pending_count'  => $this->pendingCount($employee),
            'latest_payslip' => $this->latestPayslip($employee),
        ];
    }

    private function todaysShift(Employee $employee): ?array
    {
        $today = now()->toDateString();
        if (! Schema::hasTable('employee_shift_assignments') || ! Schema::hasTable('shifts')) {
            return null;
        }

        $row = DB::table('employee_shift_assignments as a')
            ->join('shifts as s', 's.id', '=', 'a.shift_id')
            ->where('a.employee_id', $employee->id)
            ->where(function ($q) use ($today) {
                $q->whereNull('a.end_date')
                  ->orWhere('a.end_date', '>=', $today);
            })
            ->where('a.effective_date', '<=', $today)
            ->orderByDesc('a.effective_date')
            ->select('s.name', 's.start_time', 's.end_time')
            ->first();

        return $row
            ? ['name' => $row->name, 'time_in' => $row->start_time, 'time_out' => $row->end_time]
            : null;
    }

    /**
     * @return array<int, array{code: string, name: string, total: float, used: float, remaining: float}>
     */
    private function leaveBalances(Employee $employee): array
    {
        $year = (int) now()->format('Y');
        if (! Schema::hasTable('employee_leave_balances') || ! Schema::hasTable('leave_types')) {
            return [];
        }

        return DB::table('employee_leave_balances as b')
            ->join('leave_types as t', 't.id', '=', 'b.leave_type_id')
            ->where('b.employee_id', $employee->id)
            ->where('b.year', $year)
            ->select('t.code', 't.name', 'b.total_credits', 'b.used', 'b.remaining')
            ->get()
            ->map(fn ($r) => [
                'code'      => $r->code,
                'name'      => $r->name,
                'total'     => (float) $r->total_credits,
                'used'      => (float) $r->used,
                'remaining' => (float) $r->remaining,
            ])
            ->all();
    }

    private function pendingCount(Employee $employee): int
    {
        $pending = 0;
        if (Schema::hasTable('leave_requests')) {
            $pending += (int) DB::table('leave_requests')
                ->where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->count();
        }
        if (Schema::hasTable('employee_loans')) {
            $pending += (int) DB::table('employee_loans')
                ->where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->count();
        }
        return $pending;
    }

    private function latestPayslip(Employee $employee): ?array
    {
        if (! Schema::hasTable('payrolls') || ! Schema::hasTable('payroll_periods')) {
            return null;
        }

        $row = DB::table('payrolls as p')
            ->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
            ->where('p.employee_id', $employee->id)
            ->where('pp.status', 'finalized')
            ->orderByDesc('pp.period_end')
            ->select('p.id', 'p.gross_pay', 'p.net_pay', 'pp.period_start', 'pp.period_end')
            ->first();

        return $row
            ? [
                'id'           => app('hashids')->encode((int) $row->id),
                'period_start' => (string) $row->period_start,
                'period_end'   => (string) $row->period_end,
                'gross_pay'    => (string) $row->gross_pay,
                'net_pay'      => (string) $row->net_pay,
            ]
            : null;
    }
}
