<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollPeriodService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $query = PayrollPeriod::query()->with('creator')->withCount(['payrolls']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['year'])) {
            $query->whereYear('period_start', (int) $filters['year']);
        }
        if (isset($filters['is_first_half']) && $filters['is_first_half'] !== '') {
            $query->where('is_first_half', filter_var($filters['is_first_half'], FILTER_VALIDATE_BOOLEAN));
        }
        if (isset($filters['is_thirteenth_month']) && $filters['is_thirteenth_month'] !== '') {
            $query->where('is_thirteenth_month', filter_var($filters['is_thirteenth_month'], FILTER_VALIDATE_BOOLEAN));
        }

        $sort = $filters['sort'] ?? 'period_start';
        $dir  = $filters['direction'] ?? 'desc';
        $allowed = ['period_start', 'period_end', 'payroll_date', 'status', 'created_at'];
        if (in_array($sort, $allowed, true)) {
            $query->orderBy($sort, $dir);
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);
        return $query->paginate($perPage);
    }

    public function show(PayrollPeriod $period): PayrollPeriod
    {
        return $period->loadCount('payrolls')->load(['creator', 'payrolls.employee']);
    }

    public function summary(PayrollPeriod $period): array
    {
        $row = DB::table('payrolls')
            ->where('payroll_period_id', $period->id)
            ->selectRaw('
                COUNT(*) as employee_count,
                COUNT(CASE WHEN error_message IS NOT NULL THEN 1 END) as failed_count,
                COALESCE(SUM(gross_pay), 0) as total_gross,
                COALESCE(SUM(total_deductions), 0) as total_deductions,
                COALESCE(SUM(net_pay), 0) as total_net
            ')
            ->first();

        return [
            'employee_count'   => (int) ($row->employee_count ?? 0),
            'failed_count'     => (int) ($row->failed_count ?? 0),
            'total_gross'      => number_format((float) ($row->total_gross ?? 0), 2, '.', ''),
            'total_deductions' => number_format((float) ($row->total_deductions ?? 0), 2, '.', ''),
            'total_net'        => number_format((float) ($row->total_net ?? 0), 2, '.', ''),
        ];
    }

    public function create(array $data, User $user): PayrollPeriod
    {
        return DB::transaction(function () use ($data, $user) {
            $start = CarbonImmutable::parse($data['period_start']);
            $end   = CarbonImmutable::parse($data['period_end']);

            // Reject overlap with another non-finalized period in same calendar half.
            $overlap = PayrollPeriod::query()
                ->where('is_thirteenth_month', $data['is_thirteenth_month'] ?? false)
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('period_start', [$start, $end])
                      ->orWhereBetween('period_end',  [$start, $end])
                      ->orWhere(function ($q2) use ($start, $end) {
                          $q2->where('period_start', '<=', $start)
                             ->where('period_end',   '>=', $end);
                      });
                })
                ->exists();

            if ($overlap) {
                throw new RuntimeException('A payroll period overlapping these dates already exists.');
            }

            return PayrollPeriod::create([
                'period_start'        => $start->toDateString(),
                'period_end'          => $end->toDateString(),
                'payroll_date'        => $data['payroll_date'],
                'is_first_half'       => (bool) ($data['is_first_half'] ?? true),
                'is_thirteenth_month' => (bool) ($data['is_thirteenth_month'] ?? false),
                'status'              => PayrollPeriodStatus::Draft->value,
                'created_by'          => $user->id,
            ]);
        });
    }

    /**
     * Active employees who should be included in this period's batch.
     *
     * @return Collection<int, Employee>
     */
    public function availableEmployees(PayrollPeriod $period): Collection
    {
        return Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->whereDate('date_hired', '<=', $period->period_end)
            ->orderBy('employee_no')
            ->get();
    }

    public function approve(PayrollPeriod $period): PayrollPeriod
    {
        if ($period->status !== PayrollPeriodStatus::Draft) {
            throw new RuntimeException('Only draft periods can be approved.');
        }
        // Block approval if there are still failed batch rows.
        $failed = $period->payrolls()->whereNotNull('error_message')->count();
        if ($failed > 0) {
            throw new RuntimeException("Cannot approve: {$failed} employee(s) failed computation. Resolve first.");
        }

        $period->status = PayrollPeriodStatus::Approved;
        $period->save();
        return $period->fresh();
    }

    public function finalize(PayrollPeriod $period): PayrollPeriod
    {
        if ($period->status !== PayrollPeriodStatus::Approved) {
            throw new RuntimeException('Only approved periods can be finalized.');
        }
        $period->status = PayrollPeriodStatus::Finalized;
        $period->save();
        return $period->fresh();
    }
}
