<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Resources\PayrollResource;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollController
{
    public function __construct(private readonly PayrollCalculatorService $calculator) {}

    /**
     * Lists payrolls. Server-scoped:
     *   - users with payroll.payslip.view_all → see everything
     *   - department heads → see their department's employees
     *   - everyone else → see only their own payrolls
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Payroll::query()->with(['employee.department', 'employee.position', 'period']);

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
            $pid = \App\Modules\Payroll\Models\PayrollPeriod::tryDecodeHash((string) $period);
            if ($pid) $query->where('payroll_period_id', $pid);
        }
        if ($empHash = $request->query('employee_id')) {
            $eid = \App\Modules\HR\Models\Employee::tryDecodeHash((string) $empHash);
            if ($eid) $query->where('employee_id', $eid);
        }
        if ($request->boolean('failed_only')) {
            $query->whereNotNull('error_message');
        }

        $sort = $request->query('sort', 'created_at');
        $dir  = $request->query('direction', 'desc');
        $allowed = ['created_at', 'gross_pay', 'net_pay', 'employee_id'];
        if (in_array($sort, $allowed, true)) {
            $query->orderBy($sort, $dir);
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(min((int) $request->query('per_page', 25), 100));
        return PayrollResource::collection($paginator);
    }

    public function show(Payroll $payroll, Request $request): PayrollResource
    {
        $this->authorizePayroll($payroll, $request);
        return new PayrollResource($payroll->load(['employee.department', 'employee.position', 'period', 'deductionDetails']));
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
        if (! class_exists(\App\Modules\Payroll\Services\PayslipPdfService::class)) {
            return response()->json(['message' => 'Payslip service not yet available.'], 503);
        }
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
