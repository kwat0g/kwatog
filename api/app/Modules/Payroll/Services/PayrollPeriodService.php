<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollPeriodDisbursed;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Models\DisbursementProof;
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
        $paginator = $query->paginate($perPage);

        // Attach summary as a dynamic attribute on each item so the resource
        // can render totals without per-row round trips. Single bulk query
        // grouped by period — N+1 free.
        $ids = $paginator->getCollection()->pluck('id')->all();
        if (! empty($ids)) {
            $rows = DB::table('payrolls')
                ->whereIn('payroll_period_id', $ids)
                ->groupBy('payroll_period_id')
                ->selectRaw('
                    payroll_period_id,
                    COUNT(*) as employee_count,
                    COUNT(CASE WHEN error_message IS NOT NULL THEN 1 END) as failed_count,
                    COALESCE(SUM(gross_pay), 0) as total_gross,
                    COALESCE(SUM(total_deductions), 0) as total_deductions,
                    COALESCE(SUM(net_pay), 0) as total_net
                ')
                ->get()
                ->keyBy('payroll_period_id');

            $paginator->getCollection()->each(function (PayrollPeriod $p) use ($rows) {
                $r = $rows->get($p->id);
                $p->summary = $r ? [
                    'employee_count'   => (int) $r->employee_count,
                    'failed_count'     => (int) $r->failed_count,
                    'total_gross'      => number_format((float) $r->total_gross, 2, '.', ''),
                    'total_deductions' => number_format((float) $r->total_deductions, 2, '.', ''),
                    'total_net'        => number_format((float) $r->total_net, 2, '.', ''),
                ] : null;
            });
        }

        return $paginator;
    }

    public function show(PayrollPeriod $period): PayrollPeriod
    {
        $period = $period
            ->loadCount('payrolls')
            ->load(['creator', 'payrolls.employee', 'bankFileRecords.generator', 'adjustments', 'disbursementProofs.uploader', 'disburser']);
        $period->summary = $this->summary($period);

        // Pull the journal entry number (if posted) without a full JE relation,
        // since the JE module ships in Sprint 4. This keeps the linked-records
        // panel working today.
        $entryNo = null;
        if ($period->journal_entry_id && \Illuminate\Support\Facades\Schema::hasTable('journal_entries')) {
            $entryNo = DB::table('journal_entries')
                ->where('id', $period->journal_entry_id)
                ->value('entry_number');
        }
        $period->gl_entry_number = $entryNo;

        return $period;
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

    /**
     * Compare two payroll periods: delta and % change for gross, net, deductions, headcount.
     */
    public function variance(PayrollPeriod $current, PayrollPeriod $previous): array
    {
        $curr = $this->summary($current);
        $prev = $this->summary($previous);

        $delta = fn (string $key) => round((float) $curr[$key] - (float) $prev[$key], 2);
        $pct   = fn (string $key) => (float) $prev[$key] > 0
            ? round(((float) $curr[$key] - (float) $prev[$key]) / (float) $prev[$key] * 100, 2)
            : null;

        return [
            'current'    => array_merge($curr, ['period_label' => $current->period_start . ' – ' . $current->period_end]),
            'previous'   => array_merge($prev, ['period_label' => $previous->period_start . ' – ' . $previous->period_end]),
            'delta'      => [
                'gross'      => $delta('total_gross'),
                'net'        => $delta('total_net'),
                'deductions' => $delta('total_deductions'),
                'headcount'  => $curr['employee_count'] - $prev['employee_count'],
            ],
            'pct_change' => [
                'gross'      => $pct('total_gross'),
                'net'        => $pct('total_net'),
                'deductions' => $pct('total_deductions'),
                'headcount'  => $prev['employee_count'] > 0
                    ? round(($curr['employee_count'] - $prev['employee_count']) / $prev['employee_count'] * 100, 2)
                    : null,
            ],
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

    public function markDisbursed(PayrollPeriod $period, User $user): PayrollPeriod
    {
        if ($period->status !== PayrollPeriodStatus::Finalized) {
            throw new RuntimeException('Only finalized periods can be marked as disbursed.');
        }

        // P3.4 — capture the result so we can fire the event AFTER the
        // transaction commits (avoids listeners seeing uncommitted state).
        $fresh = DB::transaction(function () use ($period, $user) {
            $proofCount = $period->disbursementProofs()->count();
            if ($proofCount === 0) {
                throw new RuntimeException('At least one disbursement proof must be uploaded before marking the period as disbursed.');
            }

            $period->status = PayrollPeriodStatus::Disbursed;
            $period->disbursement_status = 'disbursed';
            $period->disbursed_at = now();
            $period->disbursed_by = $user->id;
            $period->save();

            return $period->fresh()->load('disburser', 'disbursementProofs.uploader');
        });

        // P3.4 — fire PayrollPeriodDisbursed (not PayrollPeriodFinalized) so
        // employees do NOT receive a second "payslip ready" notification.
        event(new PayrollPeriodDisbursed($fresh));

        return $fresh;
    }

    /**
     * CA3 — Payroll pipeline view. Returns all periods for a given year,
     * including future scheduled ones that haven't been created yet.
     */
    public function pipeline(int $year): array
    {
        // Get all existing periods for the year
        $existing = PayrollPeriod::query()
            ->whereYear('period_start', $year)
            ->where('is_thirteenth_month', false)
            ->orderBy('period_start')
            ->get();

        // Attach summaries in bulk
        $ids = $existing->pluck('id')->all();
        $summaries = [];
        if (!empty($ids)) {
            $rows = DB::table('payrolls')
                ->whereIn('payroll_period_id', $ids)
                ->groupBy('payroll_period_id')
                ->selectRaw('
                    payroll_period_id,
                    COUNT(*) as employee_count,
                    COALESCE(SUM(gross_pay), 0) as total_gross,
                    COALESCE(SUM(net_pay), 0) as total_net
                ')
                ->get()
                ->keyBy('payroll_period_id');
            foreach ($rows as $pid => $r) {
                $summaries[$pid] = [
                    'employee_count' => (int) $r->employee_count,
                    'total_gross'    => number_format((float) $r->total_gross, 2, '.', ''),
                    'total_net'      => number_format((float) $r->total_net, 2, '.', ''),
                ];
            }
        }

        // Build periods list — 24 half-month slots per year
        $periods = [];
        for ($month = 1; $month <= 12; $month++) {
            foreach ([true, false] as $isFirstHalf) {
                $start = CarbonImmutable::create($year, $month, $isFirstHalf ? 1 : 16);
                $end = $isFirstHalf
                    ? CarbonImmutable::create($year, $month, 15)
                    : CarbonImmutable::create($year, $month, 1)->endOfMonth()->startOfDay();

                $match = $existing->first(function ($p) use ($start) {
                    return $p->period_start->format('Y-m-d') === $start->format('Y-m-d');
                });

                if ($match) {
                    $periods[] = [
                        'id'              => $match->hash_id,
                        'period_start'    => $match->period_start->format('Y-m-d'),
                        'period_end'      => $match->period_end->format('Y-m-d'),
                        'is_first_half'   => (bool) $match->is_first_half,
                        'status'          => $match->status?->value,
                        'status_label'    => $match->status?->label(),
                        'is_auto_created' => (bool) $match->is_auto_created,
                        'employee_count'  => $summaries[$match->id]['employee_count'] ?? 0,
                        'total_gross'     => $summaries[$match->id]['total_gross'] ?? '0.00',
                        'total_net'       => $summaries[$match->id]['total_net'] ?? '0.00',
                        'label'           => $match->label(),
                        'exists'          => true,
                    ];
                } else {
                    $label = $start->format('M j') . '–' . $end->format('M j, Y');
                    $periods[] = [
                        'id'              => null,
                        'period_start'    => $start->format('Y-m-d'),
                        'period_end'      => $end->format('Y-m-d'),
                        'is_first_half'   => $isFirstHalf,
                        'status'          => $start->isFuture() ? 'scheduled' : 'not_created',
                        'status_label'    => $start->isFuture() ? 'Scheduled' : 'Not Created',
                        'is_auto_created' => false,
                        'employee_count'  => 0,
                        'total_gross'     => '0.00',
                        'total_net'       => '0.00',
                        'label'           => $label,
                        'exists'          => false,
                    ];
                }
            }
        }

        // Auto-schedule config
        $autoScheduleEnabled = (bool) DB::table('settings')
            ->where('key', 'payroll.auto_schedule')
            ->value('value');

        // Next auto-run date
        $now = CarbonImmutable::now();
        $nextRun = null;
        if ($now->day <= 14) {
            $nextRun = $now->copy()->day(14)->setTime(23, 0)->format('M j \a\t g:i A');
        } else {
            $nextRun = $now->copy()->endOfMonth()->startOfDay()->setTime(23, 0)->format('M j \a\t g:i A');
        }

        return [
            'year'                  => $year,
            'periods'               => $periods,
            'auto_schedule_enabled' => $autoScheduleEnabled,
            'next_auto_run'         => $nextRun,
        ];
    }

    public function finalize(PayrollPeriod $period): PayrollPeriod
    {
        if ($period->status !== PayrollPeriodStatus::Approved) {
            throw new RuntimeException('Only approved periods can be finalized.');
        }

        // Task A9 — block finalization while unresolved anomaly flags exist.
        $unresolved = \App\Modules\Payroll\Models\PayrollAnomalyFlag::query()
            ->where('payroll_period_id', $period->id)
            ->where('is_resolved', false)
            ->count();
        if ($unresolved > 0) {
            throw new RuntimeException("Cannot finalize: {$unresolved} unresolved payroll anomaly flag(s). Review and resolve them first.");
        }

        // P3.5 — wrap the status mutation in a transaction so any DB write
        // that throws rolls back atomically, matching other lifecycle methods.
        // The event is fired AFTER commit so listeners see persisted state.
        $fresh = DB::transaction(function () use ($period) {
            $period->status = PayrollPeriodStatus::Finalized;
            $period->save();
            return $period->fresh();
        });

        // Series C — Task C3. Domain event for chain listeners
        // (NotifyEmployeesOnPayrollFinalized + future per-employee payslip
        // PDF dispatch). Best-effort dispatch is fine here — the period is
        // already finalized regardless of listener health.
        event(new PayrollPeriodFinalized($fresh));

        return $fresh;
    }
}
