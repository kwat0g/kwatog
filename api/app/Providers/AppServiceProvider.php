<?php

declare(strict_types=1);

namespace App\Providers;

use App\Common\Models\ApprovalRecord;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\ScheduleTickFailureTracker;
use App\Common\Services\SchedulerExecutionLedger;
use App\Common\Services\SettingsService;
use App\Common\Support\OutboxDispatchContext;
use App\Common\Support\ProductionAssertions;
use App\Modules\Accounting\Events\BudgetActualsSyncRequested;
use App\Modules\Accounting\Listeners\AutoCreateBillOnGrnAccepted;
use App\Modules\Accounting\Listeners\NotifyFinanceOnDeliveryConfirmed;
use App\Modules\Accounting\Listeners\RunBudgetActualsSyncOnRequested;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Observers\JournalEntryObserver;
use App\Modules\Assets\Events\MonthlyDepreciationRequested;
use App\Modules\Assets\Listeners\RunMonthlyDepreciationOnRequested;
use App\Modules\Attendance\Events\OvertimeRequestDecided;
use App\Modules\Attendance\Events\OvertimeRequestSubmitted;
use App\Modules\Attendance\Listeners\NotifyOnOvertimeDecided;
use App\Modules\Attendance\Listeners\NotifyOnOvertimeSubmitted;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\B2B\Services\SupplierPortalDispatchGateway;
use App\Modules\CRM\Events\ComplaintNcrRequested;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Listeners\AutoSpawn8DOnNcrRecurrence;
use App\Modules\CRM\Listeners\CreateNcrOnComplaintRequested;
use App\Modules\CRM\Listeners\NotifyOnSalesOrderConfirmed;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Dashboard\Observers\BadgeInvalidationObserver;
use App\Modules\HR\Events\ClearanceFullySigned;
use App\Modules\HR\Events\EmployeeCreated;
use App\Modules\HR\Events\SeparationInitiated;
use App\Modules\HR\Exports\EmployeeMasterExport;
use App\Modules\HR\Listeners\AutoProvisionUserOnEmployeeHire;
use App\Modules\HR\Listeners\DeactivateAccountOnClearanceComplete;
use App\Modules\HR\Listeners\InitializeLeaveBalances;
use App\Modules\HR\Listeners\NotifyOnSeparationInitiated;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\Inventory\Events\GoodsReceiptNoteAccepted;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Inventory\Events\LowStockPrCreated;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Events\StockMovementGlPostingRequested;
use App\Modules\Inventory\Listeners\CheckReorderPoint;
use App\Modules\Inventory\Listeners\CreateDraftGrnOnPoSent;
use App\Modules\Inventory\Listeners\NotifyOnGrnReceived;
use App\Modules\Inventory\Listeners\NotifyOnLowStockPrCreated;
use App\Modules\Inventory\Listeners\PostStockMovementToGlOnRequested;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Landing\Models\ContactInquiry;
use App\Modules\Leave\Events\LeaveRequestApproved;
use App\Modules\Leave\Events\LeaveRequestPendingHR;
use App\Modules\Leave\Events\LeaveRequestRejected;
use App\Modules\Leave\Events\LeaveRequestSubmitted;
use App\Modules\Leave\Events\YearEndLeaveProcessingRequested;
use App\Modules\Leave\Listeners\NotifyOnLeaveApproved;
use App\Modules\Leave\Listeners\NotifyOnLeavePendingHR;
use App\Modules\Leave\Listeners\NotifyOnLeaveRejected;
use App\Modules\Leave\Listeners\NotifyOnLeaveSubmitted;
use App\Modules\Leave\Listeners\RunYearEndLeaveOnRequested;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Loans\Events\LoanDecided;
use App\Modules\Loans\Events\LoanSubmitted;
use App\Modules\Loans\Listeners\NotifyOnLoanDecided;
use App\Modules\Loans\Listeners\NotifyOnLoanSubmitted;
use App\Modules\Maintenance\Events\PreventiveMaintenanceGenerationRequested;
use App\Modules\Maintenance\Listeners\GeneratePreventiveMaintenanceOnRequested;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Payroll\Events\PayrollComputationRequested;
use App\Modules\Payroll\Events\PayrollGlPostingRequested;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Listeners\EmailPayslipPdfOnPayrollFinalized;
use App\Modules\Payroll\Listeners\GenerateBankFileOnPayrollFinalized;
use App\Modules\Payroll\Listeners\NotifyEmployeesOnPayrollFinalized;
use App\Modules\Payroll\Listeners\PostPayrollToGlOnRequested;
use App\Modules\Payroll\Listeners\RunPayrollComputationOnRequested;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Events\MachineBreakdownDetected;
use App\Modules\Production\Events\ProductionReceiptRequested;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Listeners\CreateProductionReceiptOnOutputRequested;
use App\Modules\Production\Listeners\HandleMachineBreakdown;
use App\Modules\Production\Listeners\NotifyOnMachineBreakdown;
use App\Modules\Production\Listeners\NotifyOnWorkOrderCompleted;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Contracts\SupplierDispatchGateway;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use App\Modules\Purchasing\Events\PurchaseOrderCancelled;
use App\Modules\Purchasing\Events\PurchaseOrderSent;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
use App\Modules\Purchasing\Events\SupplierPerformanceComputed;
use App\Modules\Purchasing\Listeners\AlertOnSupplierDeterioration;
use App\Modules\Purchasing\Listeners\CloseSupplierDispatchOnPurchaseOrderCancelled;
use App\Modules\Purchasing\Listeners\ConsolidatePurchaseOrders;
use App\Modules\Purchasing\Listeners\NotifyOnPurchaseOrderApproved;
use App\Modules\Purchasing\Listeners\NotifyOnPurchaseRequestApproved;
use App\Modules\Purchasing\Listeners\PrepareSupplierDispatch;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Quality\Events\InspectionFailed;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Events\NcrRecurrenceLinked;
use App\Modules\Quality\Listeners\AcceptGrnOnIncomingQcPass;
use App\Modules\Quality\Listeners\CreateDeliveryDraftOnQcPass;
use App\Modules\Quality\Listeners\NotifyOnInspectionFailed;
use App\Modules\Quality\Listeners\RejectGRNOnQcFail;
use App\Modules\Quality\Listeners\TriggerIncomingQC;
use App\Modules\Quality\Listeners\TriggerInProcessQC;
use App\Modules\Quality\Listeners\TriggerOutgoingQC;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\ReturnManagement\Events\ReturnInspectionRequested;
use App\Modules\ReturnManagement\Listeners\CreateReturnInspectionOnRequested;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use App\Modules\SupplyChain\Events\DeliveryInvoiceRequested;
use App\Modules\SupplyChain\Listeners\CreateDraftInvoiceOnDeliveryInvoiceRequested;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\Shipment;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class, fn ($app) => new SettingsService);
        $this->app->singleton(ScheduleTickFailureTracker::class);
        $this->app->singleton(SchedulerExecutionLedger::class);
        $this->app->bind(SupplierDispatchGateway::class, SupplierPortalDispatchGateway::class);
    }

    public function boot(): void
    {
        // Phase 2 Task 12 — fail fast in production if any security-critical
        // setting is at its dev default (HASHIDS_SALT, APP_DEBUG, APP_KEY).
        // No-op in non-production environments so tests / local dev are not
        // affected. See App\Common\Support\ProductionAssertions for the rules.
        ProductionAssertions::assertSafeOrFail();

        // Series E (Task E2) — register exportable columns once per process.
        // ColumnSelectorModal in the SPA reads these from
        // GET /api/v1/exports/{module}/columns.
        EmployeeMasterExport::registerColumns();

        // Attach the durable event identity to queued listener payloads. The
        // queue lifecycle listeners below use it to record completion and
        // failure against the correct chain step.
        Queue::createPayloadUsing(static fn (): array => OutboxDispatchContext::payload());
        Event::listen(ScheduledTaskFailed::class, [ScheduleTickFailureTracker::class, 'record']);
        Event::listen(ScheduledTaskStarting::class, [SchedulerExecutionLedger::class, 'recordTaskStarting']);
        Event::listen(ScheduledTaskFinished::class, [SchedulerExecutionLedger::class, 'recordTaskFinished']);
        Event::listen(ScheduledTaskFailed::class, [SchedulerExecutionLedger::class, 'recordTaskFailed']);
        Event::listen(JobProcessing::class, [ChainListenerRunService::class, 'markProcessing']);
        Event::listen(JobProcessed::class, [ChainListenerRunService::class, 'markProcessed']);
        Event::listen(JobExceptionOccurred::class, [ChainListenerRunService::class, 'markRetrying']);
        Event::listen(JobFailed::class, [ChainListenerRunService::class, 'markFailed']);

        // Keep N+1 detection + lazy-loading prevention in non-prod, but allow
        // accessing attributes that weren't selected in column-restricted
        // eager loads. The latter caused dozens of MissingAttributeException
        // 500s where a Resource read e.g. vendor.contact_person while the
        // service projected only `vendor:id,name`. Tightening every projection
        // by hand is a never-ending audit; the runtime cost of returning the
        // missing column as null is negligible.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        // NOTE: deliberately NOT calling preventAccessingMissingAttributes()
        // — see comment above.

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Sprint 4: invalidate financial-statement caches on JE mutation.
        JournalEntry::observe(JournalEntryObserver::class);

        // Polish Task S2 (real-time): bump the badge cache version + broadcast
        // BadgesChanged on any write to a model that backs a sidebar badge, so
        // connected clients refetch their counts instantly. StockLevel is
        // intentionally excluded — its write frequency would flood the channel;
        // low-stock relies on the 30s cache + 60s SPA poll instead.
        //
        // 2026-08-08 scope pass: added the models backing the widened badge set
        // (inquiries, complaints, inspections, GRN, MRB holds, shipments, MRP
        // plans, returns, invoices, bills) plus Item, EmployeeTraining and
        // SalesOrder whose badges previously only refreshed on the TTL/poll.
        foreach ([
            ApprovalRecord::class,
            PurchaseRequest::class,
            LeaveRequest::class,
            OvertimeRequest::class,
            MaintenanceWorkOrder::class,
            NonConformanceReport::class,
            ProfileUpdateRequest::class,
            WorkOrder::class,
            Delivery::class,
            PayrollPeriod::class,
            SalesOrder::class,
            Item::class,
            EmployeeTraining::class,
            JobPosting::class,
            ContactInquiry::class,
            CustomerComplaint::class,
            Inspection::class,
            GoodsReceiptNote::class,
            MaterialReviewRecord::class,
            Shipment::class,
            MrpPlan::class,
            ReturnRequest::class,
            Invoice::class,
            Bill::class,
        ] as $badgeModel) {
            $badgeModel::observe(BadgeInvalidationObserver::class);
        }

        // Sprint 5: low-stock auto-replenishment listener.
        Event::listen(StockMovementCompleted::class, [CheckReorderPoint::class, 'handle']);

        // Task 8: notify purchasing officer and warehouse staff when an auto-PR is created for a low-stock item.
        Event::listen(LowStockPrCreated::class, [NotifyOnLowStockPrCreated::class, 'handle']);

        // Sprint 6 Task 56: machine breakdown / restoration handling.
        Event::listen(MachineStatusChanged::class, [HandleMachineBreakdown::class, 'handle']);

        // Task 7: notify maintenance techs and production manager on breakdown.
        Event::listen(MachineBreakdownDetected::class, [NotifyOnMachineBreakdown::class, 'handle']);

        // Task A4: Notify Finance when a delivery is confirmed (draft invoice ready).
        Event::listen(DeliveryConfirmed::class, [NotifyFinanceOnDeliveryConfirmed::class, 'handle']);
        Event::listen(BudgetActualsSyncRequested::class, [RunBudgetActualsSyncOnRequested::class, 'handle']);
        Event::listen(PreventiveMaintenanceGenerationRequested::class, [GeneratePreventiveMaintenanceOnRequested::class, 'handle']);
        Event::listen(MonthlyDepreciationRequested::class, [RunMonthlyDepreciationOnRequested::class, 'handle']);
        Event::listen(DeliveryInvoiceRequested::class, [CreateDraftInvoiceOnDeliveryInvoiceRequested::class, 'handle']);
        Event::listen(ProductionReceiptRequested::class, [CreateProductionReceiptOnOutputRequested::class, 'handle']);
        Event::listen(StockMovementGlPostingRequested::class, [PostStockMovementToGlOnRequested::class, 'handle']);
        Event::listen(ReturnInspectionRequested::class, [CreateReturnInspectionOnRequested::class, 'handle']);
        Event::listen(ComplaintNcrRequested::class, [CreateNcrOnComplaintRequested::class, 'handle']);

        // ─── Series C — Chain orchestrator listeners (C1, C2, C3) ─────
        // C1 Order-to-Cash
        Event::listen(SalesOrderConfirmed::class, [NotifyOnSalesOrderConfirmed::class, 'handle']);
        // ADV7 — auto-trigger in-process QC on WO start.
        Event::listen(WorkOrderStatusChanged::class, [TriggerInProcessQC::class,          'handle']);
        Event::listen(WorkOrderCompleted::class, [TriggerOutgoingQC::class,           'handle']);
        Event::listen(WorkOrderCompleted::class, [NotifyOnWorkOrderCompleted::class,  'handle']);
        Event::listen(InspectionPassed::class, [CreateDeliveryDraftOnQcPass::class, 'handle']);
        Event::listen(InspectionPassed::class, [AcceptGrnOnIncomingQcPass::class,      'handle']);

        // C2 Procure-to-Pay
        Event::listen(GoodsReceiptNoteCreated::class, [TriggerIncomingQC::class,                'handle']);
        Event::listen(GoodsReceiptNoteCreated::class, [NotifyOnGrnReceived::class,              'handle']);
        // Auto-bill chain — GRN accepted → draft supplier bill for review.
        Event::listen(GoodsReceiptNoteAccepted::class, [AutoCreateBillOnGrnAccepted::class,      'handle']);
        Event::listen(PurchaseRequestApproved::class, [NotifyOnPurchaseRequestApproved::class,  'handle']);
        Event::listen(PurchaseRequestApproved::class, [ConsolidatePurchaseOrders::class,        'handle']);
        Event::listen(PurchaseOrderApproved::class, [NotifyOnPurchaseOrderApproved::class,    'handle']);
        Event::listen(PurchaseOrderApproved::class, [PrepareSupplierDispatch::class,           'handle']);
        Event::listen(PurchaseOrderCancelled::class, [CloseSupplierDispatchOnPurchaseOrderCancelled::class, 'handle']);
        Event::listen(PurchaseOrderSent::class, [CreateDraftGrnOnPoSent::class,           'handle']);
        // T3.3.C — Supplier deterioration alert (score drop >= 20 vs prior month).
        Event::listen(SupplierPerformanceComputed::class, [AlertOnSupplierDeterioration::class, 'handle']);
        Event::listen(InspectionFailed::class, [RejectGRNOnQcFail::class,                'handle']);
        Event::listen(InspectionFailed::class, [NotifyOnInspectionFailed::class,         'handle']);

        // T3.2.C — Auto-spawn 8D shell when a customer-complaint NCR recurs.
        Event::listen(NcrRecurrenceLinked::class, [AutoSpawn8DOnNcrRecurrence::class,       'handle']);

        // C3 Hire-to-Retire
        Event::listen(EmployeeCreated::class, [InitializeLeaveBalances::class,             'handle']);
        Event::listen(EmployeeCreated::class, [AutoProvisionUserOnEmployeeHire::class,     'handle']);
        Event::listen(SeparationInitiated::class, [NotifyOnSeparationInitiated::class,         'handle']);
        Event::listen(ClearanceFullySigned::class, [DeactivateAccountOnClearanceComplete::class, 'handle']);
        Event::listen(PayrollPeriodFinalized::class, [NotifyEmployeesOnPayrollFinalized::class,   'handle']);
        Event::listen(PayrollPeriodFinalized::class, [GenerateBankFileOnPayrollFinalized::class,  'handle']);
        Event::listen(PayrollPeriodFinalized::class, [EmailPayslipPdfOnPayrollFinalized::class,   'handle']);
        Event::listen(PayrollComputationRequested::class, [RunPayrollComputationOnRequested::class, 'handle']);
        Event::listen(PayrollGlPostingRequested::class, [PostPayrollToGlOnRequested::class,         'handle']);

        // Leave lifecycle notifications
        Event::listen(LeaveRequestSubmitted::class, [NotifyOnLeaveSubmitted::class, 'handle']);
        Event::listen(LeaveRequestPendingHR::class, [NotifyOnLeavePendingHR::class, 'handle']);
        Event::listen(LeaveRequestApproved::class, [NotifyOnLeaveApproved::class, 'handle']);
        Event::listen(LeaveRequestRejected::class, [NotifyOnLeaveRejected::class, 'handle']);
        Event::listen(YearEndLeaveProcessingRequested::class, [RunYearEndLeaveOnRequested::class, 'handle']);

        // Overtime lifecycle notifications
        Event::listen(OvertimeRequestSubmitted::class, [NotifyOnOvertimeSubmitted::class, 'handle']);
        Event::listen(OvertimeRequestDecided::class, [NotifyOnOvertimeDecided::class,   'handle']);

        // Loan lifecycle notifications
        Event::listen(LoanSubmitted::class, [NotifyOnLoanSubmitted::class, 'handle']);
        Event::listen(LoanDecided::class, [NotifyOnLoanDecided::class,   'handle']);
    }
}
