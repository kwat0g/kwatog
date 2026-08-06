<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\MaterialIssueStatus;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Resolves configurable dashboard widgets from live transactional tables. */
class DashboardWidgetDataService
{
    public function __construct(private readonly SettingsService $settings) {}
    /** @return array<string, array{key:string,value:string|null,kind:string,helper:?string,available:bool,updated_at:string}> */
    public function summaries(array $keys, User $user): array
    {
        $result = [];
        foreach (array_values(array_unique($keys)) as $key) {
            try {
                $result[$key] = array_merge(
                    ['key' => $key, 'available' => true, 'updated_at' => now()->toIso8601String()],
                    $this->summary($key, $user),
                );
            } catch (Throwable) {
                $result[$key] = [
                    'key' => $key, 'value' => null, 'kind' => 'number',
                    'helper' => 'Live data source unavailable.',
                    'available' => false,
                    'updated_at' => now()->toIso8601String(),
                ];
            }
        }

        return $result;
    }

    /** @return array{value:string|null,kind:string,helper:?string} */
    private function summary(string $key, User $user): array
    {
        $today = now()->toDateString();
        $ganttDays = $this->settings->requiredInt('dashboard.widgets.gantt_horizon_days', 0);
        $payablesDays = $this->settings->requiredInt('dashboard.widgets.payables_horizon_days', 0);
        $probationDays = $this->settings->requiredInt('dashboard.widgets.probation_horizon_days', 0);
        $deliveryDays = $this->settings->requiredInt('dashboard.widgets.delivery_horizon_days', 0);
        $employeeId = $user->employee_id ? (int) $user->employee_id : null;
        $departmentId = $employeeId ? DB::table('employees')->where('id', $employeeId)->value('department_id') : null;

        return match ($key) {
            'production.kpi' => $this->number(
                DB::table('work_order_outputs')->whereDate('recorded_at', $today)->sum(DB::raw('good_count + reject_count')),
                'units recorded today',
            ),
            'production.active_wo' => $this->number(DB::table('work_orders')->whereIn('status', [
                WorkOrderStatus::Confirmed->value,
                WorkOrderStatus::InProgress->value,
                WorkOrderStatus::Paused->value,
            ])->count(), 'confirmed, running, or paused'),
            'production.wo_breakdown' => $this->breakdown('work_orders', 'status'),
            'production.gantt_mini' => $this->number(DB::table('work_orders')->whereBetween('planned_start', [now()->startOfDay(), now()->addDays($ganttDays)->endOfDay()])->whereNotIn('status', [
                WorkOrderStatus::Completed->value,
                WorkOrderStatus::Closed->value,
                WorkOrderStatus::Cancelled->value,
            ])->count(), "scheduled in the next {$ganttDays} days"),
            'machine.utilization', 'oee.gauges' => $this->ratio(DB::table('machines')->where('status', MachineStatus::Running->value)->count(), DB::table('machines')->count(), 'machines running now'),
            'machine.status' => $this->number(DB::table('machines')->where('status', MachineStatus::Running->value)->count(), DB::table('machines')->where('status', MachineStatus::Breakdown->value)->count().' in breakdown'),
            'chain.stage_breakdown' => $this->number(DB::table('sales_orders')->whereNotIn('status', [SalesOrderStatus::Delivered->value, SalesOrderStatus::Invoiced->value, SalesOrderStatus::Cancelled->value])->count(), 'active order-to-cash chains'),

            'qc.pareto' => $this->number(DB::table('inspections')->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('defect_count'), 'defects recorded this month'),
            'qc.pending_inspections' => $this->number(DB::table('inspections')->whereIn('status', [InspectionStatus::Draft->value, InspectionStatus::InProgress->value])->count(), 'awaiting completion'),
            'qc.open_ncrs' => $this->number(DB::table('non_conformance_reports')->whereNotIn('status', [NcrStatus::Closed->value, NcrStatus::Cancelled->value])->count(), 'open non-conformance reports'),
            'qc.pass_rate' => $this->inspectionPassRate(),
            'mrp.shortages' => $this->number(DB::table('mrp_plans')->where('status', 'active')->sum('shortages_found'), 'shortage lines in active plans'),
            'material.reservations' => $this->decimal(DB::table('stock_levels')->sum('reserved_quantity'), 'units currently reserved'),

            'finance.cash_position' => $this->currency($this->cashPosition(), 'posted cash-account balance'),
            'finance.ar_aging' => $this->currency(DB::table('invoices')->whereIn('status', [InvoiceStatus::Finalized->value, InvoiceStatus::Partial->value])->sum('balance'), 'open accounts receivable'),
            'finance.ap_aging' => $this->currency(DB::table('bills')->whereIn('status', [BillStatus::Unpaid->value, BillStatus::Partial->value])->sum('balance'), 'open accounts payable'),
            'finance.revenue_mtd' => $this->currency(DB::table('invoices')->whereDate('date', '>=', now()->startOfMonth()->toDateString())->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])->sum('total_amount'), 'invoiced month to date'),
            'finance.unpaid_invoices' => $this->number(DB::table('invoices')->where('balance', '>', 0)->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])->count(), 'customer invoices with balance'),
            'finance.upcoming_payables' => $this->currency(DB::table('bills')->whereBetween('due_date', [$today, now()->addDays($payablesDays)->toDateString()])->where('balance', '>', 0)->sum('balance'), "due in the next {$payablesDays} days"),

            'hr.headcount' => $this->number(DB::table('employees')->where('status', 'active')->count(), 'active employees'),
            'hr.on_leave_today' => $this->number($this->leaveCount($today), 'approved leave today'),
            'hr.team_on_leave_today' => $this->number($this->leaveCount($today, $departmentId), 'approved leave in your department'),
            'hr.team_dtr_today' => $this->number($this->attendanceCount($today, $departmentId), 'department DTR records today'),
            'hr.probation_alerts' => $this->number(DB::table('employees')->where('status', 'active')->whereBetween('date_regularized', [$today, now()->addDays($probationDays)->toDateString()])->count(), "regularization due in {$probationDays} days"),
            'payroll.upcoming' => $this->upcomingPayroll(),
            'approvals.pending' => $this->number(DB::table('approval_records')->where('action', 'pending')->count(), 'approval requests awaiting action'),

            'purchasing.open_prs' => $this->number(DB::table('purchase_requests')->whereNotIn('status', [PurchaseRequestStatus::Converted->value, PurchaseRequestStatus::Rejected->value, PurchaseRequestStatus::Cancelled->value])->count(), 'open purchase requests'),
            'purchasing.open_pos' => $this->number(DB::table('purchase_orders')->whereNotIn('status', [PurchaseOrderStatus::Received->value, PurchaseOrderStatus::Cancelled->value])->count(), 'open purchase orders'),
            'purchasing.supplier_perf' => $this->supplierPerformance(),
            'supply.overdue_deliveries' => $this->number(DB::table('deliveries')->whereDate('scheduled_date', '<', $today)->whereNotIn('status', [DeliveryStatus::Delivered->value, DeliveryStatus::Confirmed->value, DeliveryStatus::Cancelled->value])->count(), 'past scheduled date'),
            'supply.delivery_schedule' => $this->number(DB::table('deliveries')->whereBetween('scheduled_date', [$today, now()->addDays($deliveryDays)->toDateString()])->whereNotIn('status', [DeliveryStatus::Confirmed->value, DeliveryStatus::Cancelled->value])->count(), "scheduled in the next {$deliveryDays} days"),

            'inventory.low_stock' => $this->number($this->lowStockCount(), 'items at or below reorder point'),
            'inventory.pending_grns' => $this->number(DB::table('goods_receipt_notes')->whereNotIn('status', [GrnStatus::Accepted->value, GrnStatus::Rejected->value])->count(), 'receipts awaiting completion'),
            'inventory.pending_issues' => $this->number(DB::table('material_issue_slips')->where('status', MaterialIssueStatus::Draft->value)->count(), 'material issues not completed'),

            'self.payslip_summary' => $this->latestPayslip($employeeId),
            'self.leave_balance' => $employeeId
                ? $this->decimal(DB::table('employee_leave_balances')->where('employee_id', $employeeId)->sum('remaining'), 'remaining leave days')
                : ['value' => null, 'kind' => 'decimal', 'helper' => 'No employee is linked to this account'],
            'self.dtr_today' => $employeeId
                ? $this->hours(DB::table('attendances')->where('employee_id', $employeeId)->whereDate('date', $today)->value('regular_hours'), 'regular hours recorded today')
                : ['value' => null, 'kind' => 'hours', 'helper' => 'No employee is linked to this account'],
            'self.pending_requests' => $this->number($this->selfPendingCount($employeeId), 'your pending requests'),
            'alerts' => $this->number(DB::table('alerts')->where('is_dismissed', false)->count(), 'open operational alerts'),
            default => throw new \InvalidArgumentException("Unsupported dashboard widget: {$key}"),
        };
    }

    private function number(mixed $value, ?string $helper): array { return ['value' => (string) (int) $value, 'kind' => 'number', 'helper' => $helper]; }
    private function decimal(mixed $value, ?string $helper): array { return ['value' => number_format((float) $value, 2, '.', ''), 'kind' => 'decimal', 'helper' => $helper]; }
    private function currency(mixed $value, ?string $helper): array { return ['value' => number_format((float) $value, 2, '.', ''), 'kind' => 'currency', 'helper' => $helper]; }
    private function hours(mixed $value, ?string $helper): array
    {
        return [
            'value' => $value === null ? null : number_format((float) $value, 2, '.', ''),
            'kind' => 'hours',
            'helper' => $value === null ? 'No hours recorded yet' : $helper,
        ];
    }
    private function ratio(int $part, int $total, string $helper): array
    {
        // A percentage with no observations is unknown, not zero. Returning
        // null keeps empty dashboards from presenting a fabricated KPI.
        return [
            'value' => $total > 0 ? number_format(($part / $total) * 100, 1, '.', '') : null,
            'kind' => 'percent',
            'helper' => $total > 0 ? $helper : 'No observations yet',
        ];
    }

    private function breakdown(string $table, string $column): array
    {
        $rows = DB::table($table)->select($column, DB::raw('COUNT(*) AS aggregate'))->groupBy($column)->orderByDesc('aggregate')->get();
        return $this->number($rows->sum('aggregate'), $rows->take(3)->map(fn ($r) => "{$r->{$column}}: {$r->aggregate}")->implode(' · '));
    }

    private function inspectionPassRate(): array
    {
        $passed = DB::table('inspections')->where('status', 'passed')->count();
        $failed = DB::table('inspections')->where('status', 'failed')->count();
        return $this->ratio($passed, $passed + $failed, 'completed inspections passed');
    }

    private function cashPosition(): float
    {
        $cashCode = $this->settings->requiredString('accounting.accounts.cash_code');
        return (float) DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('j.status', 'posted')->where(function ($q) use ($cashCode) { $q->where('a.code', $cashCode)->orWhereRaw('LOWER(a.name) LIKE ?', ['%cash%']); })->sum(DB::raw('l.debit - l.credit'));
    }

    private function leaveCount(string $today, mixed $departmentId = null): int
    {
        return DB::table('leave_requests as lr')->join('employees as e', 'e.id', '=', 'lr.employee_id')->where('lr.status', 'approved')->whereDate('lr.start_date', '<=', $today)->whereDate('lr.end_date', '>=', $today)->when($departmentId, fn ($q) => $q->where('e.department_id', $departmentId))->count();
    }

    private function attendanceCount(string $today, mixed $departmentId): int
    {
        return DB::table('attendances as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->whereDate('a.date', $today)->when($departmentId, fn ($q) => $q->where('e.department_id', $departmentId))->count();
    }

    private function upcomingPayroll(): array
    {
        $row = DB::table('payroll_periods')->where('is_thirteenth_month', false)->whereDate('payroll_date', '>=', now()->toDateString())->where('status', '!=', 'voided')->orderBy('payroll_date')->first();
        return $row ? ['value' => (string) $row->payroll_date, 'kind' => 'date', 'helper' => (string) $row->status] : ['value' => null, 'kind' => 'date', 'helper' => 'No upcoming payroll period'];
    }

    private function supplierPerformance(): array
    {
        $latest = DB::table('supplier_performance_snapshots')->orderByDesc('period_year')->orderByDesc('period_month')->first(['period_year', 'period_month']);
        if (! $latest) return ['value' => null, 'kind' => 'percent', 'helper' => 'No computed supplier score yet'];
        $avg = DB::table('supplier_performance_snapshots')->where('period_year', $latest->period_year)->where('period_month', $latest->period_month)->avg('overall_score');
        return ['value' => $avg === null ? null : number_format((float) $avg, 1, '.', ''), 'kind' => 'percent', 'helper' => sprintf('%04d-%02d overall score', $latest->period_year, $latest->period_month)];
    }

    private function lowStockCount(): int
    {
        return DB::query()->fromSub(DB::table('items as i')->leftJoin('stock_levels as sl', 'sl.item_id', '=', 'i.id')->where('i.is_active', true)->groupBy('i.id', 'i.reorder_point')->selectRaw('i.id, i.reorder_point, COALESCE(SUM(sl.quantity - sl.reserved_quantity), 0) AS available'), 'stock')->whereColumn('available', '<=', 'reorder_point')->count();
    }

    private function latestPayslip(?int $employeeId): array
    {
        $row = $employeeId ? DB::table('payrolls as p')->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')->where('p.employee_id', $employeeId)->whereIn('pp.status', ['finalized', 'disbursed'])->orderByDesc('pp.period_end')->first(['p.net_pay', 'pp.period_end']) : null;
        return $row ? $this->currency($row->net_pay, 'net pay · '.$row->period_end) : ['value' => null, 'kind' => 'currency', 'helper' => 'No finalized payslip yet'];
    }

    private function selfPendingCount(?int $employeeId): int
    {
        if (! $employeeId) return 0;
        return (int) DB::table('leave_requests')->where('employee_id', $employeeId)->where('status', 'pending')->count()
            + (int) DB::table('overtime_requests')->where('employee_id', $employeeId)->where('status', 'pending')->count()
            + (int) DB::table('profile_update_requests')->where('employee_id', $employeeId)->whereIn('status', ['pending', 'pending_finance'])->count();
    }
}
