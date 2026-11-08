<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Observers;

use App\Common\Models\Alert;
use App\Common\Models\ApprovalDelegation;
use App\Common\Models\ApprovalRecord;
use App\Common\Models\AuditLog;
use App\Common\Models\ChainListenerRun;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Admin\Models\LoginHistory;
use App\Modules\Assets\Models\Asset;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Complaint8DReport;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\PriceRequest;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Dashboard\Models\KpiDefinition;
use App\Modules\Dashboard\Models\KpiSnapshot;
use App\Modules\Dashboard\Services\AdminDashboardRealtimeService;
use App\Modules\Dashboard\Services\Analytics\AnalyticsCache;
use App\Modules\Dashboard\Services\ChainBottlenecksRealtimeService;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;
use App\Modules\Dashboard\Services\EmployeeDashboardRealtimeService;
use App\Modules\Dashboard\Services\FinanceDashboardRealtimeService;
use App\Modules\Dashboard\Services\HrDashboardRealtimeService;
use App\Modules\Dashboard\Services\PlantManagerDashboardRealtimeService;
use App\Modules\Dashboard\Services\PpcDashboardRealtimeService;
use App\Modules\Dashboard\Services\PurchasingDashboardRealtimeService;
use App\Modules\Dashboard\Services\QualityDashboardRealtimeService;
use App\Modules\Dashboard\Services\WarehouseDashboardRealtimeService;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\HR\Models\SalaryAdjustment;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\TransferOrder;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\InventoryDashboardCache;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Models\LoanPayment;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\MRP\Models\MrpRun;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderDefect;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Services\ProductionDashboardService;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Models\SupplierPerformanceSnapshot;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionDefectivePiece;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionPieceReading;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Models\Shipment;
use Illuminate\Database\Eloquent\Model;

/** Retires cached dashboard aggregates after source models change. */
final class DashboardCacheInvalidationObserver
{
    /** Rich renderings have a separate payload generation from their scalar fallback. */
    private const RICH_WIDGET_KEYS = [
        'approvals.pending', 'assets.under_maintenance', 'budget.utilization', 'chain.stage_breakdown',
        'crm.open_complaints', 'executive.risks_opportunities', 'executive.weekly_briefing',
        'finance.ar_aging', 'forecast.attendance', 'forecast.capacity', 'forecast.cash_flow',
        'hr.headcount', 'loans.outstanding', 'machine.utilization', 'oee.gauges',
        'production.kpi', 'production.wo_breakdown', 'purchasing.open_pos', 'qc.pareto',
        'quality.eightd_sla', 'quality.ncr_aging', 'rma.open_returns', 'rma.pending_approval',
        'sales.open_orders', 'sales.order_intake', 'sales.price_requests',
        'supply.delivery_schedule', 'supply.overdue_deliveries',
    ];

    /** @var array<class-string<Model>, list<string>> */
    private const SCOPES_BY_MODEL = [
        // System-admin snapshot sources. A client-side refetch alone is not
        // enough while the service cache still points at its old generation.
        Alert::class => [
            'dashboard:admin',
            'widget-summary:executive.risks_opportunities',
        ],
        ApprovalDelegation::class => ['widget-summary:approvals.pending'],
        AuditLog::class => ['dashboard:admin'],
        LoginHistory::class => ['dashboard:admin'],
        Role::class => ['dashboard:admin'],
        User::class => ['dashboard:admin'],
        // Finance summary inputs have their own generation so a record ping
        // cannot refetch a still-current 30-second Finance snapshot. Keep this
        // list ahead of each source model's realtime observer registration.
        Account::class => [
            'dashboard:finance',
            'dashboard:plant_manager',
            'widget-summary:finance.cash_position',
            'widget-summary:forecast.revenue',
        ],
        AccountingPeriod::class => ['dashboard:finance'],
        Bill::class => [
            'dashboard:finance',
            'widget-summary:finance.ap_aging',
            'widget-summary:finance.upcoming_payables',
            'widget-summary:executive.risks_opportunities',
            'widget-summary:budget.utilization',
            'widget-summary:forecast.cash_flow',
            'widget-rich:forecast.cash_flow',
            'dashboard:plant_manager',
        ],
        Budget::class => ['dashboard:finance', 'widget-summary:budget.utilization'],
        BudgetLineItem::class => ['dashboard:finance', 'widget-summary:budget.utilization'],
        BudgetTransfer::class => ['dashboard:finance', 'widget-summary:budget.utilization'],
        CreditNote::class => ['dashboard:finance'],
        Customer::class => ['dashboard:finance', 'dashboard:warehouse'],
        Warehouse::class => ['dashboard:warehouse'],
        WarehouseZone::class => ['dashboard:warehouse'],
        WarehouseLocation::class => ['dashboard:warehouse'],
        TransferOrder::class => ['dashboard:warehouse'],
        Department::class => ['dashboard:finance', 'dashboard:purchasing', 'dashboard:hr'],
        FiscalYear::class => ['dashboard:finance', 'widget-summary:budget.utilization'],
        Invoice::class => [
            'dashboard:finance',
            'widget-summary:finance.ar_aging',
            'widget-summary:finance.revenue_mtd',
            'widget-summary:finance.unpaid_invoices',
            'widget-summary:executive.risks_opportunities',
            'widget-summary:forecast.cash_flow',
            'widget-rich:forecast.cash_flow',
            'dashboard:plant_manager',
        ],
        JournalEntry::class => [
            'dashboard:finance',
            'widget-summary:finance.cash_position',
            'widget-summary:budget.utilization',
            'widget-summary:forecast.revenue',
            'dashboard:plant_manager',
        ],
        PayrollPeriod::class => [
            'dashboard:finance',
            'dashboard:hr',
            'dashboard:employee',
            'widget-summary:payroll.upcoming',
        ],
        Vendor::class => ['dashboard:finance', 'dashboard:purchasing', 'dashboard:warehouse'],
        ApprovedSupplier::class => ['dashboard:warehouse'],
        // Scalar/rich dashboard tiles have their own short-lived generations.
        // A record ping alone is insufficient: the follow-up API read must not
        // be allowed to hit the 30-second snapshot created before the write.
        Attendance::class => [
            'dashboard:hr',
            'dashboard:employee',
            'widget-summary:hr.team_dtr_today',
            'widget-summary:self.dtr_today',
            'widget-summary:forecast.attendance',
            'widget-rich:forecast.attendance',
        ],
        CustomerComplaint::class => [
            'widget-summary:crm.open_complaints',
            'widget-rich:crm.open_complaints',
            'widget-summary:quality.eightd_sla',
            'widget-summary:executive.risks_opportunities',
            'widget-summary:executive.weekly_briefing',
        ],
        Complaint8DReport::class => [
            'widget-summary:quality.eightd_sla',
            'widget-summary:executive.risks_opportunities',
            'widget-summary:executive.weekly_briefing',
        ],
        Clearance::class => ['dashboard:hr'],
        ChainListenerRun::class => [
            'widget-summary:chain.recovery_backlog',
            'widget-summary:executive.risks_opportunities',
        ],
        Delivery::class => [
            'dashboard:warehouse',
            'dashboard:ppc',
            'dashboard:finance',
            'dashboard:plant_manager',
            'widget-summary:finance.invoice_handoff_backlog',
            'widget-summary:supply.overdue_deliveries',
            'widget-summary:supply.delivery_schedule',
        ],
        DeliveryProof::class => ['dashboard:quality'],
        DemandForecast::class => ['dashboard:purchasing'],
        Employee::class => [
            'dashboard:hr',
            'dashboard:employee',
            'widget-summary:hr.headcount',
            'widget-summary:hr.probation_alerts',
            'widget-summary:forecast.headcount',
            'widget-summary:forecast.attendance',
            'widget-rich:forecast.attendance',
        ],
        EmployeeLeaveBalance::class => [
            'dashboard:employee',
            'widget-summary:self.leave_balance',
        ],
        EmployeeLoan::class => ['widget-summary:loans.outstanding'],
        KpiDefinition::class => [],
        GoodsReceiptNote::class => [
            'dashboard:quality',
            'dashboard:warehouse',
            'widget-summary:budget.utilization',
            'widget-summary:inventory.pending_grns',
            'widget-summary:inventory.qc_handoff_issues',
        ],
        GrnItem::class => ['dashboard:warehouse'],
        Inspection::class => [
            'dashboard:warehouse',
            'dashboard:quality',
            'dashboard:ppc',
            'dashboard:plant_manager',
            'widget-summary:qc.pareto',
            'widget-summary:qc.pending_inspections',
            'widget-summary:qc.pass_rate',
            'widget-summary:forecast.defect_rate',
            'widget-rich:qc.pareto',
        ],
        Item::class => ['dashboard:warehouse', 'dashboard:purchasing'],
        InspectionDefectivePiece::class => [
            'dashboard:quality',
            'widget-summary:qc.pareto',
            'widget-rich:qc.pareto',
        ],
        InspectionMeasurement::class => [
            'dashboard:quality',
            'widget-summary:qc.pareto',
            'widget-rich:qc.pareto',
        ],
        InspectionPieceReading::class => [
            'dashboard:quality',
            'widget-summary:qc.pareto',
            'widget-rich:qc.pareto',
        ],
        LeaveRequest::class => [
            'dashboard:hr',
            'dashboard:employee',
            'widget-summary:hr.on_leave_today',
            'widget-summary:hr.team_on_leave_today',
            'widget-summary:self.pending_requests',
            'widget-summary:forecast.attendance',
            'widget-rich:forecast.attendance',
        ],
        LoanPayment::class => ['widget-summary:loans.outstanding'],
        MaintenanceWorkOrder::class => [
            'dashboard:ppc',
            'widget-summary:maintenance.open_wos',
        ],
        MaterialIssueSlip::class => ['widget-summary:inventory.pending_issues'],
        MrpPlan::class => [
            'dashboard:ppc',
            'dashboard:plant_manager',
            'widget-summary:mrp.shortages',
        ],
        NonConformanceReport::class => [
            'dashboard:warehouse',
            'dashboard:ppc',
            'dashboard:plant_manager',
            'dashboard:quality',
            'widget-summary:qc.open_ncrs',
            'widget-summary:quality.ncr_aging',
            'widget-summary:executive.risks_opportunities',
            'widget-summary:executive.weekly_briefing',
        ],
        OvertimeRequest::class => ['widget-summary:self.pending_requests'],
        Payroll::class => [
            'dashboard:hr',
            'dashboard:employee',
            'widget-summary:self.payslip_summary',
        ],
        PriceRequest::class => [
            'widget-summary:sales.price_requests',
            'widget-rich:sales.price_requests',
        ],
        Product::class => ['dashboard:purchasing', 'dashboard:quality'],
        DefectType::class => ['dashboard:quality', 'dashboard:plant_manager'],
        ProfileUpdateRequest::class => ['dashboard:hr', 'widget-summary:self.pending_requests'],
        SalaryAdjustment::class => ['dashboard:hr'],
        Holiday::class => ['dashboard:hr', 'dashboard:employee'],
        PurchaseOrder::class => [
            'dashboard:warehouse',
            'dashboard:ppc',
            'dashboard:purchasing',
            'widget-summary:purchasing.open_pos',
            'widget-summary:budget.utilization',
        ],
        PurchaseRequest::class => [
            'dashboard:warehouse',
            'dashboard:ppc',
            'dashboard:plant_manager',
            'dashboard:purchasing',
            'widget-summary:purchasing.open_prs',
        ],
        PurchaseOrderItem::class => [
            'dashboard:warehouse',
            'dashboard:purchasing',
            'widget-summary:budget.utilization',
        ],
        PurchaseRequestItem::class => ['dashboard:warehouse', 'dashboard:purchasing'],
        SalesOrder::class => [
            'dashboard:warehouse',
            'dashboard:ppc',
            'dashboard:plant_manager',
            'widget-summary:chain.stage_breakdown',
            'widget-summary:sales.open_orders',
            'widget-summary:sales.order_intake',
            'widget-rich:chain.stage_breakdown',
        ],
        StockMovement::class => ['dashboard:warehouse'],
        Shipment::class => [
            'dashboard:plant_manager',
            'widget-summary:logistics.shipments_in_transit',
        ],
        SupplierPerformanceSnapshot::class => [
            'widget-summary:purchasing.supplier_perf',
            'dashboard:purchasing',
        ],
        Asset::class => [
            'dashboard:plant_manager',
            'widget-summary:assets.under_maintenance',
            'widget-rich:assets.under_maintenance',
        ],
        Machine::class => [
            'dashboard:ppc',
            'dashboard:plant_manager',
            'widget-summary:machine.utilization',
            'widget-summary:machine.status',
            'widget-summary:oee.gauges',
            'widget-summary:forecast.capacity',
            'widget-rich:forecast.capacity',
            'widget-rich:machine.utilization',
            'widget-rich:oee.gauges',
        ],
        MachineDowntime::class => [
            'dashboard:ppc',
            'dashboard:plant_manager',
            'widget-rich:machine.utilization',
            'widget-rich:oee.gauges',
        ],
        Mold::class => ['dashboard:ppc', 'dashboard:plant_manager'],
        MrpRun::class => ['dashboard:ppc'],
        MaintenanceSchedule::class => [
            'dashboard:ppc',
            'widget-summary:maintenance.due_schedules',
        ],
        WorkOrderOutput::class => [
            'dashboard:quality',
            'dashboard:plant_manager',
            'widget-summary:production.kpi',
            'widget-rich:production.kpi',
            'widget-summary:executive.weekly_briefing',
        ],
        WorkOrder::class => [
            'dashboard:quality',
            'dashboard:ppc',
            'widget-summary:production.active_wo',
            'widget-summary:production.gantt_mini',
            'widget-rich:production.wo_breakdown',
            'widget-summary:production.wo_breakdown',
            'widget-summary:forecast.capacity',
            'widget-rich:forecast.capacity',
        ],
        KpiSnapshot::class => [
            'widget-summary:forecast.capacity',
            'widget-rich:forecast.capacity',
        ],
        WorkOrderDefect::class => ['dashboard:quality', 'dashboard:plant_manager'],
        ReturnRequest::class => [
            'dashboard:warehouse',
            'widget-summary:rma.open_returns',
            'widget-summary:rma.pending_approval',
            'widget-rich:rma.open_returns',
            'widget-rich:rma.pending_approval',
        ],
    ];

    public function created(Model $model): void
    {
        $this->invalidateSource($model);
    }

    public function updated(Model $model): void
    {
        if ($model instanceof User && ! $model->wasChanged([
            'name', 'email', 'role_id', 'is_active', 'failed_login_attempts',
            'must_change_password', 'locked_until', 'deleted_at',
        ])) {
            return;
        }

        if ($model instanceof Alert && ! $model->wasChanged([
            'type', 'severity', 'title', 'message', 'entity_type', 'entity_id',
            'metadata', 'resolved_at', 'is_read', 'is_dismissed', 'dismissed_by', 'dismissed_at',
        ])) {
            return;
        }

        $this->invalidateSource($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidateSource($model);
    }

    /** Also called by shared services when a query-builder write bypasses Eloquent events. */
    public function invalidateSource(Model $model): void
    {
        // This observer is registered before the corresponding realtime
        // observers. DashboardCacheGeneration::touch() registers its cache
        // rotation with DB::afterCommit now, before those observers queue their
        // outbox dispatch callbacks, so a subscriber cannot refetch an old
        // snapshot after the domain event arrives.
        $scopes = self::SCOPES_BY_MODEL[$model::class] ?? [];
        if ($model instanceof KpiDefinition || $model instanceof KpiSnapshot) {
            $definitionCode = $model instanceof KpiDefinition
                ? (string) $model->getAttribute('code')
                : (string) (KpiDefinition::query()
                    ->whereKey((int) $model->getAttribute('definition_id'))
                    ->value('code') ?? '');
            if ($definitionCode !== '') {
                $scopes[] = 'widget-summary:kpi.'.$definitionCode;
                $scopes[] = 'widget-rich:kpi.'.$definitionCode;
            }
        }

        // Summary and rich renderings for a widget have independent cache
        // generations. Retire both from the same source map so a realtime
        // refetch cannot read an older rich payload after the scalar is fresh.
        foreach ($scopes as $scope) {
            if (str_starts_with($scope, 'widget-summary:')) {
                $widgetKey = substr($scope, strlen('widget-summary:'));
                if (in_array($widgetKey, self::RICH_WIDGET_KEYS, true)) {
                    $scopes[] = 'widget-rich:'.$widgetKey;
                }
            }
        }
        $scopes = array_values(array_unique($scopes));
        foreach ($scopes as $scope) {
            DashboardCacheGeneration::touch($scope);
        }

        // The Inventory summary also includes pending GRNs, low-stock PR/PO
        // links, and usable quantities derived from location and QC state.
        // Retire its independent generation before a corresponding refresh
        // signal can prompt clients to refetch the summary.
        if ($model instanceof GoodsReceiptNote
            || $model instanceof GrnItem
            || $model instanceof Item
            || $model instanceof StockMovement
            || $model instanceof Inspection
            || $model instanceof NonConformanceReport
            || $model instanceof PurchaseRequest
            || $model instanceof PurchaseRequestItem
            || $model instanceof PurchaseOrder
            || $model instanceof PurchaseOrderItem
            || $model instanceof ReturnRequest
            || $model instanceof Warehouse
            || $model instanceof WarehouseZone
            || $model instanceof WarehouseLocation) {
            app(InventoryDashboardCache::class)->invalidateAfterCommit();
        }

        // Retire both the Warehouse snapshot and any nested Inventory summary
        // before staging a signal that can make a client refetch either one.
        if (in_array('dashboard:warehouse', $scopes, true)
            && ! ($model instanceof Warehouse)
            && ! ($model instanceof WarehouseZone)
            && ! ($model instanceof WarehouseLocation)) {
            app(WarehouseDashboardRealtimeService::class)->stage();
        }

        if (in_array('dashboard:admin', $scopes, true)) {
            // Register cache retirement before queuing the corresponding ping,
            // so authorized readers cannot refetch the previous snapshot.
            app(AdminDashboardRealtimeService::class)->stage();
        }

        if (in_array('dashboard:finance', $scopes, true)) {
            // Finance readers can receive approved aggregate panels without
            // access to every source record channel. Refresh the snapshot
            // through the caller's own panel-gated API response.
            app(FinanceDashboardRealtimeService::class)->stage();
        }

        if (in_array('dashboard:hr', $scopes, true)) {
            // Generation retirement is registered above; dispatching this
            // signal after commit lets viewers refetch the committed snapshot.
            app(HrDashboardRealtimeService::class)->stage();
        }

        // Employee snapshots include personal leave balances, leave requests,
        // and payslips. Their readers cannot join the corresponding HR/payroll
        // list channels, so target the affected employee; payroll-period
        // changes can affect every linked employee and use the shared channel.
        if (in_array('dashboard:employee', $scopes, true)) {
            $employeeRealtime = app(EmployeeDashboardRealtimeService::class);
            if ($model instanceof PayrollPeriod) {
                $employeeRealtime->stageForAllEmployees();
            } elseif ($model instanceof EmployeeLeaveBalance || $model instanceof LeaveRequest || $model instanceof Payroll) {
                $employeeRealtime->stageForEmployee((int) $model->getAttribute('employee_id'));
            }
        }

        if (in_array('dashboard:quality', $scopes, true)) {
            // These source rows may change for users who cannot subscribe to
            // the individual entity channel. Every viewer refetches the
            // authorized dashboard after this generation has been retired.
            app(QualityDashboardRealtimeService::class)->stage();
        }

        if (in_array('dashboard:plant_manager', $scopes, true)) {
            // This viewer can see aggregates from several source modules
            // without joining their record channels. Refetch through the
            // dashboard API after the scoped generation above has rotated.
            app(PlantManagerDashboardRealtimeService::class)->stage();
        }

        if (in_array('dashboard:ppc', $scopes, true)) {
            // PPC aggregates span modules whose record channels are not
            // available to every dashboard reader. Invalidate only through
            // this permission-scoped, content-free signal.
            app(PpcDashboardRealtimeService::class)->stage();
        }

        // Purchasing panels join item/product, department, vendor and demand
        // forecast data under their own panel grants. Their readers may lack
        // the source-record list permission, so send the aggregate-only ping
        // for these cache sources instead of relying on a narrower list event.
        if (in_array('dashboard:purchasing', $scopes, true)
            && ($model instanceof Item
                || $model instanceof Product
                || $model instanceof Department
                || $model instanceof Vendor)) {
            app(PurchasingDashboardRealtimeService::class)->stage();
        }

        // The legacy production dashboard has its own generation marker and
        // reads order statuses, work-order state, and recorded output. Register
        // this after-commit rotation during the model lifecycle so it precedes
        // any outbox dispatch registered later in the same transaction.
        if ($model instanceof SalesOrder
            || $model instanceof WorkOrder
            || $model instanceof WorkOrderOutput
            || $model instanceof WorkOrderDefect) {
            ProductionDashboardService::forgetCache();
        }

        // These two live metrics use AnalyticsCache's independent generation,
        // not the ordinary dashboard/widget generations above. Retire their
        // metric keys before the corresponding model observer stages a ping.
        if ($model instanceof ReturnRequest) {
            app(AnalyticsCache::class)->flush('returns.pipeline');
        } elseif ($model instanceof WorkOrder) {
            app(AnalyticsCache::class)->flush('production.wo_lead_time');
        }

        // The bottleneck API reads these rows directly across several modules.
        // Coalesce source writes by transaction and send no record data; viewers
        // refetch through their own permission-filtered API request.
        if ($this->isChainBottleneckSource($model)) {
            app(ChainBottlenecksRealtimeService::class)->stage();
        }
    }

    private function isChainBottleneckSource(Model $model): bool
    {
        return $model instanceof ApprovalRecord
            || $model instanceof ChainListenerRun
            || $model instanceof SalesOrder
            || $model instanceof WorkOrder
            || $model instanceof Inspection
            || $model instanceof Delivery
            || $model instanceof Invoice
            || $model instanceof PurchaseRequest
            || $model instanceof Bill
            || $model instanceof GoodsReceiptNote
            || $model instanceof GrnItem
            || $model instanceof WorkOrderOutput
            || $model instanceof StockMovement
            || $model instanceof ReturnRequest
            || $model instanceof ReturnRequestItem
            || $model instanceof CustomerComplaint
            || $model instanceof PayrollPeriod;
    }
}
