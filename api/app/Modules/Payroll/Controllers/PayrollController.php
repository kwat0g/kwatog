<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Resources\PayrollResource;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use App\Modules\Payroll\Services\PayrollPublicationPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollController
{
    public function __construct(
        private readonly PayrollCalculatorService $calculator,
        private readonly PayrollPublicationPolicy $publication,
    ) {}

    /**
     * Lists payrolls. Server-scoped:
     *   - users with payroll.payslip.view_all → see everything
     *   - department heads → see their department's employees
     *   - everyone else → see only their own payrolls
     *
     * Publication-gated: this is the EMPLOYEE-facing collection (self-service
     * payslips, adjustment pickers), so a row only appears once its period is
     * finalized. The staff working view of a run in progress is
     * {@see self::indexForPeriod()}.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Payroll::query()->with(['employee.department', 'employee.position', 'period']);
        $this->publication->scopePublishable($query);

        $hasViewAll = $user?->hasPermission('payroll.payslip.view_all') ?? false;
        $isAdmin    = $user?->role?->slug === 'system_admin';

        if (! $hasViewAll && ! $isAdmin) {
            $employeeId = $user?->employee_id;
            if ($user?->role?->slug === 'department_head' && $employeeId) {
                $deptId = \App\Modules\HR\Models\Employee::query()->whereKey($employeeId)->value('department_id');
                if ($deptId) {
                    $query->whereHas('employee', fn ($q) => $q->where('department_id', $deptId));
                } else {
                    $query->whereRaw('1=0'); // no dept → no rows
                }
            } else {
                if ($employeeId) {
                    $query->where('employee_id', $employeeId);
                } else {
                    $query->whereRaw('1=0');
                }
            }
        }

        if ($period = $request->query('period_id')) {
            $pid = PayrollPeriod::tryDecodeHash((string) $period);
            if ($pid) $query->where('payroll_period_id', $pid);
        }

        $this->applyListFilters($query, $request);
        $this->applyListSort($query, $request);

        return PayrollResource::collection($query->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    /**
     * The run's own rows — the staff working list for a period under review.
     *
     * Deliberately NOT publication-gated: a run is checked while it is still
     * `computed`, so the period-detail Employees/Failures tabs must see rows
     * before finalize. The route requires `payroll.periods.view` (payroll staff
     * only), and "may open the period" already implies "may read its rows", so
     * there is no second row scope here — one rule, not two.
     */
    public function indexForPeriod(PayrollPeriod $period, Request $request): AnonymousResourceCollection
    {
        $query = Payroll::query()
            ->with(['employee.department', 'employee.position', 'period'])
            ->where('payroll_period_id', $period->id);

        $this->applyListFilters($query, $request);
        $this->applyListSort($query, $request);

        return PayrollResource::collection($query->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    /** @param Builder<Payroll> $query */
    private function applyListFilters(Builder $query, Request $request): void
    {
        if ($empHash = $request->query('employee_id')) {
            $eid = \App\Modules\HR\Models\Employee::tryDecodeHash((string) $empHash);
            if ($eid) $query->where('employee_id', $eid);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->whereHas('employee', function ($employeeQuery) use ($search): void {
                $employeeQuery->where('employee_no', 'ilike', "%{$search}%")
                    ->orWhere('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%");
            });
        }
        if ($request->boolean('failed_only')) {
            $query->whereNotNull('error_message');
        }
    }

    /** @param Builder<Payroll> $query */
    private function applyListSort(Builder $query, Request $request): void
    {
        $sort = $request->query('sort', 'created_at');
        // Same hole as the periods list: the column was whitelisted but the
        // direction was passed straight to orderBy(), which throws
        // InvalidArgumentException on anything but asc/desc — a 500, not a 422.
        $dir = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowed = ['created_at', 'gross_pay', 'net_pay', 'employee_id'];
        if (in_array($sort, $allowed, true)) {
            $query->orderBy($sort, $dir);
        }
    }

    public function show(Payroll $payroll, Request $request): PayrollResource
    {
        $this->authorizePayroll($payroll, $request);
        if (! $this->mayReadRunRows($request->user())) {
            $this->publication->assertPayrollPublishable($payroll);
        }
        return new PayrollResource($payroll->load(['employee.department', 'employee.position', 'period', 'deductionDetails']));
    }

    /**
     * Payroll staff read a run's rows while it is still being checked; the
     * publication boundary applies to everyone else (the employee it belongs
     * to, and the PDF/document paths — see {@see self::payslip()}).
     */
    private function mayReadRunRows(?User $user): bool
    {
        return $user?->hasPermission('payroll.periods.view') ?? false;
    }


    public function recompute(Payroll $payroll, Request $request): PayrollResource
    {
        $this->authorizePayroll($payroll, $request);
        $period   = $payroll->period;
        $employee = $payroll->employee;
        $fresh    = $this->calculator->computeForEmployee($period, $employee);
        return new PayrollResource($fresh);
    }

    public function payslip(Payroll $payroll, Request $request)
    {
        $this->authorizePayroll($payroll, $request);
        $this->publication->assertPayrollPublishable($payroll);
        /** @var \App\Modules\Payroll\Services\PayslipPdfService $svc */
        $svc = app(\App\Modules\Payroll\Services\PayslipPdfService::class);
        return $svc->stream($payroll, $request->user());
    }

    private function authorizePayroll(Payroll $payroll, Request $request): void
    {
        $user = $request->user();
        $isAdmin = $user?->role?->slug === 'system_admin';
        $hasAll  = $user?->hasPermission('payroll.payslip.view_all') ?? false;
        if ($isAdmin || $hasAll) return;

        // Payroll staff review the whole run (the period-detail drill-down).
        // They may already open the period; reading one of its rows adds no
        // exposure. The publication gate still applies to anyone without it.
        if ($this->mayReadRunRows($user)) return;

        if ($user?->employee_id && (int) $user->employee_id === (int) $payroll->employee_id) return;

        // Department head can view their own department's payrolls.
        if ($user?->role?->slug === 'department_head') {
            $deptId = \App\Modules\HR\Models\Employee::query()->whereKey($user->employee_id)->value('department_id');
            $payrollDept = \App\Modules\HR\Models\Employee::query()->whereKey($payroll->employee_id)->value('department_id');
            if ($deptId && $deptId === $payrollDept) return;
        }

        abort(403, 'You do not have permission to view this payroll.');
    }
}
