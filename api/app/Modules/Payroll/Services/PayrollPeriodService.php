<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\PayType;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollPeriodDisbursed;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Events\PayrollPeriodVoided;
use App\Modules\Payroll\Jobs\ProcessPayrollJob;
use App\Modules\Payroll\Models\DisbursementProof;
use App\Modules\Payroll\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PayrollPeriodService
{
    public function __construct(
        private readonly \App\Common\Services\SettingsService $settings,
        private readonly PayrollProgressTracker $progress,
    ) {}

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
            ->load(['creator', 'payrolls.employee', 'bankFileRecords.generator', 'adjustments', 'disburser', 'voider', 'computer', 'approver', 'finalizer'])
            ->load(['disbursementProofs' => fn ($q) => $q->withTrashed()->with('uploader')]);
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
            $isThirteenth = (bool) ($data['is_thirteenth_month'] ?? false);

            // The half is DERIVED from the dates, not taken from the operator.
            //
            // It used to be a free checkbox describing a window that already
            // implies the answer, and the mismatch was exploitable: enter
            // Nov 16–30 but tick "1st half", then Nov 1–15 ticked "2nd half",
            // and the two periods produce inverted cycle keys — the double-pay
            // guard saw different cycles and paid the same employee twice for
            // November. It also decided which run withholds government
            // contributions, so a mislabel moved SSS/PhilHealth/Pag-IBIG/BIR
            // onto the wrong cutoff.
            //
            // A 13th-month period is a whole-year window and is exempt.
            if (! $isThirteenth) {
                $this->assertCutoffDoesNotStraddleHalves($start, $end);
            }
            $isFirstHalf = $isThirteenth
                ? (bool) ($data['is_first_half'] ?? false)
                : PayrollPeriod::deriveIsFirstHalf($start);

            $this->assertPayrollDateIsPlausible($start, $end, CarbonImmutable::parse($data['payroll_date']));

            $scope = $this->normalizeScope($data);

            // ─── Overlap rules ───────────────────────────────────
            // Scoping deliberately allows several periods to share one date
            // window (Regulars now, Contractuals tomorrow), so a blanket
            // date-overlap ban no longer works. Instead:
            //
            //  1. A company-wide period may not coexist with ANY other period
            //     over the same dates — it already pays everybody.
            //  2. Two scoped periods may share dates only if their scopes are
            //     disjoint, i.e. no employee satisfies both.
            //
            // Rule 2 is checked against real employee sets rather than by
            // comparing filter arrays: an employment-type scope and a
            // department scope can look disjoint on paper and still both match
            // the same person.
            $siblings = PayrollPeriod::query()
                ->where('is_thirteenth_month', $isThirteenth)
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('period_start', [$start, $end])
                      ->orWhereBetween('period_end',  [$start, $end])
                      ->orWhere(function ($q2) use ($start, $end) {
                          $q2->where('period_start', '<=', $start)
                             ->where('period_end',   '>=', $end);
                      });
                })
                // A voided run has been withdrawn; it must not block a
                // replacement covering the same people and dates.
                ->where('status', '!=', PayrollPeriodStatus::Voided->value)
                ->get();

            if ($siblings->isNotEmpty()) {
                $this->assertScopeDoesNotCollide($scope, $siblings, $start, $end);
            }

            $period = PayrollPeriod::create([
                'period_start'        => $start->toDateString(),
                'period_end'          => $end->toDateString(),
                'payroll_date'        => $data['payroll_date'],
                'is_first_half'       => $isFirstHalf,
                'is_thirteenth_month' => $isThirteenth,
                'created_by'          => $user->id,
                'scope_employment_types' => $scope['employment_types'],
                'scope_department_ids'   => $scope['department_ids'],
                'scope_pay_types'        => $scope['pay_types'],
                'scope_label'            => $scope['label'],
            ]);
            // status non-fillable; service-only.
            $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();

            return $period;
        });
    }

    /**
     * The payroll date must plausibly belong to the cutoff it pays.
     *
     * It is not a cosmetic field. `payroll_date` selects:
     *
     *   - the effective-dated government contribution tables (SSS, PhilHealth,
     *     Pag-IBIG, BIR) — `bracketsEffectiveOn()` takes the newest table
     *     effective on or before it
     *   - the de minimis month whose taxable excess is added to the WHT base
     *   - the GL posting date of the payroll journal entry
     *   - loan payment_date and a paid loan's end_date
     *
     * The FormRequest only required `payroll_date >= period_end`, so an August
     * 2029 cutoff could carry a 2034 payroll date and be computed against a
     * different year's statutory tables. That is a silent ₱100/employee swing on
     * SSS alone between the 2024 and 2025 schedules — wrong money withheld and
     * wrong remittance filed, with nothing in the UI to hint at it.
     *
     * The allowed window is period_end through period_end + grace days
     * (`payroll.payroll_date.max_days_after_period_end`, default 45). That is
     * generous enough for a delayed run or a cutoff paid the following month,
     * while making a wrong year impossible.
     */
    private function assertPayrollDateIsPlausible(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $payrollDate): void
    {
        if ($payrollDate->lt($start)) {
            throw new BusinessRuleException(sprintf(
                'The payroll date (%s) cannot fall before the cutoff it pays (%s–%s).',
                $payrollDate->toDateString(),
                $start->toDateString(),
                $end->toDateString(),
            ));
        }

        $graceDays = $this->payrollDateGraceDays();
        $latest    = $end->addDays($graceDays);

        if ($payrollDate->gt($latest)) {
            throw new BusinessRuleException(sprintf(
                'The payroll date (%s) is more than %d days after the cutoff ends (%s). It selects the government contribution tables and the GL posting date, so a date in the wrong month or year withholds and remits the wrong amounts. Use a date on or before %s.',
                $payrollDate->toDateString(),
                $graceDays,
                $end->toDateString(),
                $latest->toDateString(),
            ));
        }
    }

    /**
     * How long after a cutoff ends a payroll date may still fall.
     *
     * Configurable because pay calendars differ, but floored at 1 day so a
     * mis-set value cannot make every period unsaveable.
     */
    private function payrollDateGraceDays(): int
    {
        $configured = $this->settings->get('payroll.payroll_date.max_days_after_period_end');

        return is_numeric($configured) && (int) $configured >= 1 ? (int) $configured : 45;
    }

    /**
     * A semi-monthly cutoff must sit inside ONE half of ONE month.
     *
     * Without this, a window like Aug 10–20 belongs to both halves and a window
     * like Aug 20 – Sep 10 belongs to two months. Either way the derived cycle
     * key describes only the half the window STARTS in, so the other half stays
     * unclaimed and can be paid again — the same double-pay hole the derived key
     * closes, reopened by an ambiguous window.
     *
     * Boundaries: first half is day 1–15, second half is day 16 to month end.
     */
    private function assertCutoffDoesNotStraddleHalves(CarbonImmutable $start, CarbonImmutable $end): void
    {
        if ($start->format('Y-m') !== $end->format('Y-m')) {
            throw new BusinessRuleException(sprintf(
                'A payroll cutoff must stay within one month. %s–%s spans %s and %s — create one period per month.',
                $start->toDateString(),
                $end->toDateString(),
                $start->format('F Y'),
                $end->format('F Y'),
            ));
        }

        $startsFirstHalf = PayrollPeriod::deriveIsFirstHalf($start);
        $endsFirstHalf   = PayrollPeriod::deriveIsFirstHalf($end);

        if ($startsFirstHalf !== $endsFirstHalf) {
            throw new BusinessRuleException(sprintf(
                'A payroll cutoff must stay within one half of the month. %s–%s crosses the 15th/16th boundary — use %s 1–15 for the first half or %s 16–%s for the second.',
                $start->toDateString(),
                $end->toDateString(),
                $start->format('M'),
                $start->format('M'),
                $start->endOfMonth()->format('j'),
            ));
        }
    }

    /**
     * Coerce raw request scope input into canonical, validated arrays.
     *
     * Empty selections normalise to null (not []), so "company-wide" has ONE
     * representation in the database and isCompanyWide() cannot be fooled by an
     * empty JSON array.
     *
     * @return array{employment_types: array<int,string>|null, department_ids: array<int,int>|null, pay_types: array<int,string>|null, label: string|null}
     */
    private function normalizeScope(array $data): array
    {
        // Same widening hazard as departments below: an unrecognised value must
        // not be quietly discarded, or a mistyped filter turns a targeted run
        // into a company-wide one. The FormRequest already rejects these on the
        // HTTP path; this covers service-level callers (seeders, commands).
        $employmentTypes = array_values(array_unique(array_map(
            'strval',
            array_filter((array) ($data['scope_employment_types'] ?? []), fn ($v) => $v !== null && $v !== ''),
        )));
        foreach ($employmentTypes as $value) {
            if (EmploymentType::tryFrom($value) === null) {
                throw new BusinessRuleException("Unknown employment type '{$value}' in payroll period scope.");
            }
        }

        $payTypes = array_values(array_unique(array_map(
            'strval',
            array_filter((array) ($data['scope_pay_types'] ?? []), fn ($v) => $v !== null && $v !== ''),
        )));
        foreach ($payTypes as $value) {
            if (PayType::tryFrom($value) === null) {
                throw new BusinessRuleException("Unknown pay type '{$value}' in payroll period scope.");
            }
        }

        // Department ids arrive as hash ids from the SPA; decode and verify every
        // one. A silently dropped id would WIDEN the run — ask for "Production
        // only", mistype the id, and the filter vanishes so the period pays
        // everyone. Fail loudly instead of quietly paying the wrong people.
        $rawDepartments = array_filter(
            (array) ($data['scope_department_ids'] ?? []),
            fn ($v) => $v !== null && $v !== '',
        );
        $departmentIds = [];
        foreach ($rawDepartments as $raw) {
            $decoded = is_numeric($raw)
                ? (int) $raw
                : \App\Modules\HR\Models\Department::tryDecodeHash((string) $raw);
            if ($decoded) {
                $departmentIds[] = (int) $decoded;
            }
        }
        $departmentIds = array_values(array_unique($departmentIds));

        if ($rawDepartments !== []) {
            $existing = \App\Modules\HR\Models\Department::query()
                ->whereIn('id', $departmentIds)
                ->pluck('id')
                ->all();

            // Covers both failure modes: an id that would not decode at all, and
            // one that decoded to a department that has since been deleted.
            if (count($existing) !== count($rawDepartments)) {
                throw new BusinessRuleException('One or more selected departments no longer exist. Reload the page and pick them again.');
            }
        }

        $label = isset($data['scope_label']) && trim((string) $data['scope_label']) !== ''
            ? trim((string) $data['scope_label'])
            : null;

        return [
            'employment_types' => $employmentTypes === [] ? null : $employmentTypes,
            'department_ids'   => $departmentIds === [] ? null : $departmentIds,
            'pay_types'        => $payTypes === [] ? null : $payTypes,
            'label'            => $label,
        ];
    }

    /**
     * Refuse a new period whose scope would pay someone an existing period
     * over the same dates already covers.
     *
     * @param  array{employment_types: array<int,string>|null, department_ids: array<int,int>|null, pay_types: array<int,string>|null, label: string|null}  $scope
     * @param  Collection<int, PayrollPeriod>  $siblings
     */
    private function assertScopeDoesNotCollide(array $scope, Collection $siblings, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $newIsCompanyWide = $scope['employment_types'] === null
            && $scope['department_ids'] === null
            && $scope['pay_types'] === null;

        if ($newIsCompanyWide) {
            throw new BusinessRuleException(
                'A payroll period overlapping these dates already exists. Scope this run (employment type, department or pay type) so it does not pay the same employees twice.',
            );
        }

        $companyWideSibling = $siblings->first(fn (PayrollPeriod $p) => $p->isCompanyWide());
        if ($companyWideSibling) {
            throw new BusinessRuleException(sprintf(
                'A company-wide payroll period (%s) already covers these dates, so it already pays these employees.',
                $companyWideSibling->label(),
            ));
        }

        // Resolve the actual employee set the new scope would pay, then look for
        // anyone a sibling scope also pays.
        $newIds = $this->employeeIdsForScope($scope, $end);
        if ($newIds === []) {
            return; // nothing to collide with; the empty-scope guard fires at compute time
        }
        $newIdSet = array_flip($newIds);

        foreach ($siblings as $sibling) {
            $siblingIds = $this->employeeIdsForScope([
                'employment_types' => $sibling->scope_employment_types,
                'department_ids'   => $sibling->scope_department_ids,
                'pay_types'        => $sibling->scope_pay_types,
            ], CarbonImmutable::parse($sibling->period_end));

            $shared = [];
            foreach ($siblingIds as $id) {
                if (isset($newIdSet[$id])) {
                    $shared[] = $id;
                }
            }

            if ($shared !== []) {
                $names = Employee::query()
                    ->whereIn('id', array_slice($shared, 0, 3))
                    ->orderBy('employee_no')
                    ->get(['employee_no', 'first_name', 'last_name'])
                    ->map(fn (Employee $e) => "{$e->employee_no} {$e->first_name} {$e->last_name}")
                    ->implode(', ');

                throw new BusinessRuleException(sprintf(
                    'This scope overlaps period %s: %d employee(s) would be paid twice for %s–%s (e.g. %s).',
                    $sibling->label(),
                    count($shared),
                    $start->toDateString(),
                    $end->toDateString(),
                    $names,
                ));
            }
        }
    }

    /**
     * Employee ids matching a scope as of a cut-off date.
     *
     * @param  array{employment_types: array<int,string>|null, department_ids: array<int,int>|null, pay_types: array<int,string>|null}  $scope
     * @return array<int, int>
     */
    private function employeeIdsForScope(array $scope, CarbonImmutable $asOf): array
    {
        return $this->scopedEmployeeQuery($scope, $asOf)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Base query for "employees this scope pays".
     *
     * ONE definition, shared by the collision check and the compute batch, so a
     * period can never be validated against a different employee set than the
     * one it actually pays.
     *
     * @param  array{employment_types: array<int,string>|null, department_ids: array<int,int>|null, pay_types: array<int,string>|null}  $scope
     */
    private function scopedEmployeeQuery(array $scope, CarbonImmutable $asOf): \Illuminate\Database\Eloquent\Builder
    {
        $query = Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->whereDate('date_hired', '<=', $asOf->toDateString());

        if (! empty($scope['employment_types'])) {
            $query->whereIn('employment_type', (array) $scope['employment_types']);
        }
        if (! empty($scope['department_ids'])) {
            $query->whereIn('department_id', (array) $scope['department_ids']);
        }
        if (! empty($scope['pay_types'])) {
            $query->whereIn('pay_type', (array) $scope['pay_types']);
        }

        return $query;
    }

    /**
     * Dry-run a scope before the period exists.
     *
     * Answers the two questions that otherwise only surface after Compute:
     * how many people would this pay, and is any of them already paid for this
     * cutoff by another period?
     *
     * @param  array<string, mixed>  $data
     * @return array{
     *   is_company_wide: bool,
     *   employee_count: int,
     *   already_paid_count: int,
     *   already_paid_sample: array<int, array{employee_no: string, name: string, period: string}>,
     *   total_active: int,
     *   estimated_gross: string,
     * }
     */
    public function scopePreview(array $data): array
    {
        $end   = CarbonImmutable::parse($data['period_end']);
        $scope = $this->normalizeScope($data);

        $employees = $this->scopedEmployeeQuery([
            'employment_types' => $scope['employment_types'],
            'department_ids'   => $scope['department_ids'],
            'pay_types'        => $scope['pay_types'],
        ], $end)->get(['id', 'employee_no', 'first_name', 'last_name', 'pay_type', 'basic_monthly_salary', 'semi_monthly_rate']);

        // Estimated gross = one cutoff of basic for each matched employee. A
        // rough figure by design (no attendance yet), but enough for HR to
        // sanity-check that a run is the size they expect.
        $estimatedGross = '0.00';
        foreach ($employees as $employee) {
            $monthly = $employee->monthlyEquivalentSalary();
            if ($monthly !== null) {
                $estimatedGross = bcadd($estimatedGross, bcdiv($monthly, '2', 2), 2);
            }
        }

        // Who is already claimed for this exact cycle? Derives the half from the
        // dates exactly as cycleKey() does, so the preview and the enforcement
        // can never disagree about which cycle is being checked.
        $probe = new PayrollPeriod([
            'period_start'        => $data['period_start'],
            'period_end'          => $data['period_end'],
            'is_first_half'       => PayrollPeriod::deriveIsFirstHalf($data['period_start']),
            'is_thirteenth_month' => (bool) ($data['is_thirteenth_month'] ?? false),
        ]);

        $claimed = \App\Modules\Payroll\Models\PayrollCycleClaim::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->where('cycle_key', $probe->cycleKey())
            ->with(['employee:id,employee_no,first_name,last_name', 'period'])
            ->get();

        return [
            'is_company_wide'    => $scope['employment_types'] === null
                && $scope['department_ids'] === null
                && $scope['pay_types'] === null,
            'employee_count'     => $employees->count(),
            'already_paid_count' => $claimed->count(),
            'already_paid_sample' => $claimed->take(5)->map(fn ($c) => [
                'employee_no' => (string) ($c->employee?->employee_no ?? ''),
                'name'        => trim(($c->employee?->first_name ?? '').' '.($c->employee?->last_name ?? '')),
                'period'      => (string) ($c->period?->label() ?? ''),
            ])->values()->all(),
            'total_active'    => Employee::query()
                ->where('status', EmployeeStatus::Active->value)
                ->whereDate('date_hired', '<=', $end->toDateString())
                ->count(),
            'estimated_gross' => $estimatedGross,
        ];
    }

    /**
     * Active employees who should be included in this period's batch.
     *
     * Honours the period's scope filters. An unscoped period returns every
     * active employee, exactly as before scoping existed.
     *
     * @return Collection<int, Employee>
     */
    public function availableEmployees(PayrollPeriod $period): Collection
    {
        return $this->scopedEmployeeQuery([
            'employment_types' => $period->scope_employment_types,
            'department_ids'   => $period->scope_department_ids,
            'pay_types'        => $period->scope_pay_types,
        ], CarbonImmutable::parse($period->period_end))
            ->orderBy('employee_no')
            ->get();
    }

    /**
     * How long a Processing claim may sit before it is presumed dead.
     *
     * Must exceed ProcessPayrollJob::$timeout (1800s / 30 min) or a healthy
     * long run would be reclaimed out from under its own worker. Configurable
     * because the safe margin depends on headcount and queue contention.
     */
    public function staleAfterMinutes(): int
    {
        $configured = $this->settings->get('payroll.compute.stale_after_minutes');

        // Never allow a threshold below the job timeout — that would let a
        // healthy in-flight run be stolen mid-batch.
        $floor = (int) ceil(ProcessPayrollJob::TIMEOUT_SECONDS / 60);

        return is_numeric($configured) && (int) $configured >= $floor
            ? (int) $configured
            : max($floor, 45);
    }

    /**
     * Is this claim old enough to be presumed dead?
     *
     * A null processing_started_at on a Processing row means the stamp predates
     * the claim tracking column — treat it as stale rather than wedging the
     * period forever.
     */
    public function claimIsStale(PayrollPeriod $period): bool
    {
        if ($period->status !== PayrollPeriodStatus::Processing) {
            return false;
        }
        $startedAt = $period->processing_started_at;
        if ($startedAt === null) {
            return true;
        }

        return CarbonImmutable::parse($startedAt)->isBefore(
            CarbonImmutable::now()->subMinutes($this->staleAfterMinutes())
        );
    }

    /**
     * Release a Processing claim onto its correct terminal status.
     *
     * Computed when the run produced rows (they are real payroll awaiting a
     * checker), Draft when it produced none. Every path that ends a run — the
     * job's finally block, failed(), force-unlock and the stale reaper — goes
     * through here so they cannot drift apart on what "finished" means.
     */
    public function releaseClaim(PayrollPeriod $period, array $extraAttributes = []): PayrollPeriod
    {
        $period->forceFill(array_merge([
            'status' => $period->payrolls()->exists()
                ? PayrollPeriodStatus::Computed->value
                : PayrollPeriodStatus::Draft->value,
            'processing_started_at' => null,
        ], $extraAttributes))->save();

        return $period;
    }

    /**
     * Atomically claim a period for a compute run.
     *
     * This is the concurrency gate for the Compute button. A single UPDATE …
     * WHERE status IN (draft, computed) flips the row to Processing, and the
     * affected-row count tells us whether WE won the claim. Two simultaneous
     * clicks therefore produce exactly one dispatch — the loser gets a 422
     * instead of queueing a second full recompute of the same period.
     *
     * ShouldBeUnique on the job alone was not sufficient: it de-duplicates
     * only while the lock is held, so a click AFTER a run finished enqueued a
     * fresh recompute (which silently re-deducted loans and dropped applied
     * adjustments). Claiming synchronously also means the HTTP response already
     * carries status=processing, so the SPA can disable the button and start
     * polling immediately.
     *
     * A claim older than staleAfterMinutes() is presumed dead (worker OOM,
     * SIGKILL, container restart) and taken over. Without this the period was
     * wedged at Processing forever and every Compute click returned "already
     * being computed" — recoverable only by an admin holding
     * payroll.periods.force_unlock.
     */
    public function claimForCompute(PayrollPeriod $period, ?User $actor = null): PayrollPeriod
    {
        $status = $period->status;

        // A dead claim is takeable. Both guards below have to agree on that:
        // Processing is deliberately NOT in isComputable(), so without this the
        // staleness check passed and the very next guard still refused with
        // "Cannot compute a processing period."
        $isStaleClaim = $status === PayrollPeriodStatus::Processing && $this->claimIsStale($period);

        if ($status === PayrollPeriodStatus::Processing && ! $isStaleClaim) {
            throw new BusinessRuleException('This period is already being computed. Wait for the current run to finish.');
        }
        if ($status !== null && ! $status->isComputable() && ! $isStaleClaim) {
            throw new BusinessRuleException(sprintf(
                'Cannot compute a %s period. %s',
                strtolower($status->label()),
                match ($status) {
                    PayrollPeriodStatus::Approved  => 'Payroll has already been approved — void it or force-unlock to recompute.',
                    PayrollPeriodStatus::Finalized => 'Finalized payroll is immutable — void the period first.',
                    PayrollPeriodStatus::Disbursed => 'Salaries have already been disbursed; this run cannot be changed.',
                    PayrollPeriodStatus::Voided    => 'Create a replacement period instead.',
                    default                        => '',
                },
            ));
        }
        if ($period->is_thirteenth_month) {
            throw new BusinessRuleException('13th-month periods are generated by the 13th-month run, not by Compute.');
        }

        // A SCOPED run that matches nobody is almost always a mis-set filter, and
        // left to run it would write zero rows, park back at Draft via
        // releaseClaim(), and give the operator no clue why nothing happened.
        // Fail here instead, naming the scope.
        //
        // Deliberately NOT applied to company-wide periods: "no active employees
        // at all" is a legitimate state (fresh install, everyone separated) and
        // blocking it would change long-standing compute/claim behaviour that has
        // nothing to do with scoping.
        if (! $period->isCompanyWide() && $this->availableEmployees($period)->isEmpty()) {
            throw new BusinessRuleException(sprintf(
                'This period\'s scope (%s) matches no active employee hired on or before %s. Widen the scope, or create the period unscoped to pay everyone.',
                $period->scopeLabel() ?? 'custom',
                $period->period_end->format('Y-m-d'),
            ));
        }

        $claimed = PayrollPeriod::query()
            ->whereKey($period->id)
            ->where(function ($q) use ($period) {
                $q->whereIn('status', [
                    PayrollPeriodStatus::Draft->value,
                    PayrollPeriodStatus::Computed->value,
                ])
                // A stale Processing claim is dead (worker crashed mid-run) —
                // reclaim it so the next Compute click actually restarts the
                // batch instead of wedging the period forever.
                ->orWhere(fn ($qq) => $qq
                    ->where('status', PayrollPeriodStatus::Processing->value)
                    ->where('processing_started_at', '<', CarbonImmutable::now()->subMinutes($this->staleAfterMinutes()))
                );
            })
            ->update([
                'status'                => PayrollPeriodStatus::Processing->value,
                'processing_started_at' => now(),
                'computed_by'           => $actor?->id ?? $period->computed_by,
                'updated_at'            => now(),
            ]);

        if ($claimed === 0) {
            // Lost the race — another request claimed it between our read and
            // this UPDATE. Report the status that actually won.
            $current = $period->fresh();
            throw new BusinessRuleException(
                $current?->status === PayrollPeriodStatus::Processing && ! $this->claimIsStale($current)
                    ? 'This period is already being computed. Wait for the current run to finish.'
                    : 'This period is no longer computable. Refresh and try again.',
            );
        }

        // Drop the previous run's progress snapshot so the UI never shows a
        // stale "142 / 200" before this run's first broadcast lands.
        $fresh = $period->fresh();
        $this->progress->forget($fresh);

        return $fresh;
    }

    public function approve(PayrollPeriod $period, User $actor): PayrollPeriod
    {
        // Approve follows a completed compute run. Draft is NOT approvable: it
        // means the period was never computed, and approving it used to lock in
        // an empty ₱0 payroll that could then be finalized and posted to the GL.
        if ($period->status !== PayrollPeriodStatus::Computed) {
            throw new BusinessRuleException(
                $period->status === PayrollPeriodStatus::Draft
                    ? 'This period has not been computed yet. Run Compute before approving.'
                    : 'Only computed periods can be approved.',
            );
        }
        // Refuse to approve a run with no payroll rows at all.
        if ($period->payrolls()->count() === 0) {
            throw new BusinessRuleException('Cannot approve: this period has no payroll rows.');
        }
        // Block approval if there are still failed batch rows.
        $failed = $period->payrolls()->whereNotNull('error_message')->count();
        if ($failed > 0) {
            throw new BusinessRuleException("Cannot approve: {$failed} employee(s) failed computation. Resolve first.");
        }

        // REC-04 — maker-checker Segregation of Duties. The HR user who computed
        // this run (computed_by, stamped by ProcessPayrollJob) may NOT also
        // approve it — a second set of eyes is required on a ₱-material batch.
        // system_admin (and any holder of self_approve_override) may bypass.
        if ($period->computed_by !== null
            && $period->computed_by === $actor->id
            && ! $actor->hasPermission('payroll.periods.self_approve_override')) {
            throw new BusinessRuleException('Maker-checker: the person who computed this payroll cannot also approve it. A different approver is required.');
        }

        return DB::transaction(function () use ($period, $actor) {
            $previous = $period->status?->value;

            // status + attribution columns are non-fillable / service-only.
            // Single save() → one audit row per the repo's audit-hygiene rule.
            $period->forceFill([
                'status'      => PayrollPeriodStatus::Approved->value,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ])->save();

            AuditLog::create([
                'user_id'    => $actor->id,
                'action'     => 'payroll.period.approve',
                'model_type' => PayrollPeriod::class,
                'model_id'   => $period->id,
                'old_values' => ['status' => $previous],
                'new_values' => ['status' => PayrollPeriodStatus::Approved->value, 'approved_by' => $actor->id],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            return $period->fresh();
        });
    }

    public function markDisbursed(PayrollPeriod $period, User $user): PayrollPeriod
    {
        if ($period->status !== PayrollPeriodStatus::Finalized) {
            throw new BusinessRuleException('Only finalized periods can be marked as disbursed.');
        }

        // P3.4 — capture the result so we can fire the event AFTER the
        // transaction commits (avoids listeners seeing uncommitted state).
        $fresh = DB::transaction(function () use ($period, $user) {
            $proofCount = $period->disbursementProofs()->count();
            if ($proofCount === 0) {
                throw new BusinessRuleException('At least one disbursement proof must be uploaded before marking the period as disbursed.');
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

    public function finalize(PayrollPeriod $period, User $actor): PayrollPeriod
    {
        if ($period->status !== PayrollPeriodStatus::Approved) {
            throw new BusinessRuleException('Only approved periods can be finalized.');
        }

        // Task A9 — block finalization while unresolved anomaly flags exist.
        $unresolved = \App\Modules\Payroll\Models\PayrollAnomalyFlag::query()
            ->where('payroll_period_id', $period->id)
            ->where('is_resolved', false)
            ->count();
        if ($unresolved > 0) {
            throw new BusinessRuleException("Cannot finalize: {$unresolved} unresolved payroll anomaly flag(s). Review and resolve them first.");
        }

        // P3.5 — wrap the status mutation in a transaction so any DB write
        // that throws rolls back atomically, matching other lifecycle methods.
        // The event is fired AFTER commit so listeners see persisted state.
        // REC-04 — no self-approve block here (approve() already enforced the
        // second set of eyes); we only record the finalizer for attribution.
        $fresh = DB::transaction(function () use ($period, $actor) {
            $previous = $period->status?->value;

            $period->forceFill([
                'status'       => PayrollPeriodStatus::Finalized->value,
                'finalized_by' => $actor->id,
                'finalized_at' => now(),
            ])->save();

            // A 13th-month run recognises payment HERE, not at compute time.
            // computeAndPay() used to flip accrual.is_paid while the period was
            // still Draft, so the year read as settled before any checker had
            // seen it — and accrue() skips a paid accrual, so nothing would ever
            // pay it again. Done inside the transaction and synchronously (not
            // via a queued listener, which swallows its own failures) because a
            // finalized 13th-month period whose accruals are still unpaid would
            // be re-payable by the next run.
            app(ThirteenthMonthService::class)->markAccrualsPaidOnFinalize($period);

            AuditLog::create([
                'user_id'    => $actor->id,
                'action'     => 'payroll.period.finalize',
                'model_type' => PayrollPeriod::class,
                'model_id'   => $period->id,
                'old_values' => ['status' => $previous],
                'new_values' => ['status' => PayrollPeriodStatus::Finalized->value, 'finalized_by' => $actor->id],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            return $period->fresh();
        });

        // Series C — Task C3. Domain event for chain listeners
        // (NotifyEmployeesOnPayrollFinalized + future per-employee payslip
        // PDF dispatch). Best-effort dispatch is fine here — the period is
        // already finalized regardless of listener health.
        event(new PayrollPeriodFinalized($fresh));

        return $fresh;
    }

    /**
     * H-8 — Admin escape hatch for periods stuck in Processing because the
     * payroll job worker crashed (OOM, SIGKILL, host reboot). The job's
     * normal finally block resets status; this method covers the case where
     * finally never ran.
     *
     * Refuses to operate on any status other than Processing (cannot demote
     * Approved/Finalized/Disbursed).
     */
    public function forceUnlock(PayrollPeriod $period, User $actor, ?string $reason = null): PayrollPeriod
    {
        if ($period->status !== PayrollPeriodStatus::Processing) {
            throw new BusinessRuleException('Only periods stuck at Processing can be force-unlocked.');
        }

        return DB::transaction(function () use ($period, $actor, $reason) {
            $previous = $period->status?->value;
            // Land on Computed when rows already exist (a crash midway through
            // the batch still produced payroll), otherwise Draft. Also stamps
            // force_unlocked_by — the column existed but was never written, so
            // the audit trail could not attribute the unlock.
            $this->releaseClaim($period, ['force_unlocked_by' => $actor->id]);
            $this->progress->forget($period);

            AuditLog::create([
                'user_id'    => $actor->id,
                'action'     => 'payroll.period.force_unlock',
                'model_type' => PayrollPeriod::class,
                'model_id'   => $period->id,
                'old_values' => ['status' => $previous],
                'new_values' => ['status' => $period->status?->value, 'reason' => $reason],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            return $period->fresh();
        });
    }

    /**
     * OGAMI-011 — void a finalized payroll period.
     *
     * Only a Finalized period can be voided. If the period was posted to the
     * GL (journal_entry_id set), its journal entry is reversed via the
     * Accounting module's public JournalEntryService::reverse() — a balanced
     * mirror entry that keeps the ledger auditable (never deletes money rows).
     *
     * After voiding the period transitions to Voided. From there the period
     * can be recomputed/re-finalized (see allowedToRecompute) or a fresh
     * replacement period created. Wrapped in a transaction so a failed GL
     * reversal rolls the whole void back.
     */
    public function void(PayrollPeriod $period, User $actor, string $reason): PayrollPeriod
    {
        if ($period->status !== PayrollPeriodStatus::Finalized) {
            throw new BusinessRuleException('Only finalized periods can be voided.');
        }
        if (trim($reason) === '') {
            throw new BusinessRuleException('A void reason is required.');
        }

        [$fresh, $reversalId] = DB::transaction(function () use ($period, $actor, $reason) {
            $previousStatus = $period->status?->value;
            $reversalId = null;

            // Reverse the GL posting if one exists and the accounting tables
            // are present. JournalEntryService::reverse() posts a balanced
            // mirror entry and flags the original as reversed.
            if ($period->journal_entry_id
                && \Illuminate\Support\Facades\Schema::hasTable('journal_entries')) {
                $je = JournalEntry::find($period->journal_entry_id);
                if ($je && $je->status === JournalEntryStatus::Posted && $je->reversed_by_entry_id === null) {
                    $reversal = app(JournalEntryService::class)->reverse($je, $actor);
                    $reversalId = $reversal->id;
                }
                // If the JE is already reversed/missing we leave it — idempotent.
            }

            $period->forceFill([
                'status'      => PayrollPeriodStatus::Voided->value,
                'voided_at'   => now(),
                'voided_by'   => $actor->id,
                'void_reason' => $reason,
            ])->save();

            // Release this run's pay-cycle claims. A voided period has been
            // withdrawn, so its employees must be payable again by the
            // replacement run — otherwise the double-pay guard would refuse the
            // correction it exists to make possible. The payroll rows stay put
            // (history is never deleted); only the claim on the cycle is freed.
            \App\Modules\Payroll\Models\PayrollCycleClaim::query()
                ->where('payroll_period_id', $period->id)
                ->delete();

            // Voiding a 13th-month run must also un-recognise the payment, or the
            // accruals stay marked paid and the replacement run pays nobody:
            // computeAndPay() only picks up accruals where is_paid = false.
            if ($period->is_thirteenth_month) {
                \App\Modules\Payroll\Models\ThirteenthMonthAccrual::query()
                    ->whereIn('payroll_id', $period->payrolls()->select('id'))
                    ->update(['is_paid' => false, 'paid_date' => null, 'updated_at' => now()]);
            }

            AuditLog::create([
                'user_id'    => $actor->id,
                'action'     => 'payroll.period.void',
                'model_type' => PayrollPeriod::class,
                'model_id'   => $period->id,
                'old_values' => ['status' => $previousStatus, 'journal_entry_id' => $period->journal_entry_id],
                'new_values' => ['status' => PayrollPeriodStatus::Voided->value, 'reason' => $reason, 'reversal_journal_entry_id' => $reversalId],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            return [$period->fresh(), $reversalId];
        });

        // Fire AFTER commit so listeners see persisted state.
        event(new PayrollPeriodVoided($fresh, $reversalId));

        return $fresh;
    }
}
