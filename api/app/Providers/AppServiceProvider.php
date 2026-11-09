<?php

declare(strict_types=1);

namespace App\Providers;

use App\Common\Models\ApprovalRecord;
use App\Common\Models\ApprovalDelegation;
use App\Common\Models\Alert;
use App\Common\Models\ActivityEvent;
use App\Common\Models\AuditLog;
use App\Common\Models\BackupOperation;
use App\Common\Models\ChainListenerRun;
use App\Common\Models\Document;
use App\Common\Models\ImportBatch;
use App\Common\Models\ScheduledExport;
use App\Common\Broadcasting\StandardizedPusherBroadcaster;
use App\Common\Observers\BackupOperationRealtimeObserver;
use App\Common\Observers\ChainListenerRunRealtimeObserver;
use App\Common\Observers\ImportBatchRealtimeObserver;
use App\Common\Observers\ScheduledExportRealtimeObserver;
use App\Common\Services\CacheKeyAuditReader;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\EmailBrandingService;
use App\Common\Services\EmailDeliveryLogService;
use App\Common\Services\NonProductionEmailGuard;
use App\Common\Services\QueueRestartSignalPolicy;
use App\Common\Services\RealtimeQueueTelemetry;
use App\Common\Services\RedisCacheKeyAuditReader;
use App\Common\Services\RetiredGenerationCacheKeyReader;
use App\Common\Services\SchedulerExecutionLedger;
use App\Common\Services\ScheduleTickFailureTracker;
use App\Common\Services\SettingsService;
use App\Common\Support\OutboxDispatchContext;
use App\Common\Support\ProductionAssertions;
use App\Modules\Accounting\Events\BudgetActualsSyncRequested;
use App\Modules\Accounting\Events\CreditNoteFinalized;
use App\Modules\Accounting\Events\InvoiceFinalized;
use App\Modules\Accounting\Events\OfficialReceiptIssued;
use App\Modules\Accounting\Listeners\AutoCreateBillOnGrnAccepted;
use App\Modules\Accounting\Listeners\EmailCustomerOnInvoiceFinalized;
use App\Modules\Accounting\Listeners\EmailCustomerOnOfficialReceiptIssued;
use App\Modules\Accounting\Listeners\EmailPartyOnCreditNoteFinalized;
use App\Modules\Accounting\Listeners\NotifyFinanceOnDeliveryConfirmed;
use App\Modules\Accounting\Listeners\RunBudgetActualsSyncOnRequested;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoicePaymentSubmission;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Observers\AccountStatementCacheObserver;
use App\Modules\Accounting\Observers\AccountingPeriodRealtimeObserver;
use App\Modules\Accounting\Observers\AccountRealtimeObserver;
use App\Modules\Accounting\Observers\BudgetTransferRealtimeObserver;
use App\Modules\Accounting\Observers\BudgetRealtimeObserver;
use App\Modules\Accounting\Observers\FiscalYearRealtimeObserver;
use App\Modules\Accounting\Observers\CreditNoteRealtimeObserver;
use App\Modules\Accounting\Observers\CustomerRealtimeObserver;
use App\Modules\Accounting\Observers\JournalEntryObserver;
use App\Modules\Accounting\Observers\VendorRealtimeObserver;
use App\Modules\Admin\Listeners\RecordActivityFromEvent;
use App\Modules\Admin\Listeners\RecordAuthActivity;
use App\Modules\Admin\Models\LoginHistory;
use App\Modules\Admin\Observers\ApprovalDelegationAccessRealtimeObserver;
use App\Modules\Admin\Observers\ActivityEventRealtimeObserver;
use App\Modules\Admin\Observers\AdminAuditLogRealtimeObserver;
use App\Modules\Admin\Observers\AdminUserRealtimeObserver;
use App\Modules\Admin\Observers\AdminRoleRealtimeObserver;
use App\Modules\Assets\Events\MonthlyDepreciationRequested;
use App\Modules\Assets\Listeners\RunMonthlyDepreciationOnRequested;
use App\Modules\Assets\Models\Asset;
use App\Modules\Attendance\Events\OvertimeRequestDecided;
use App\Modules\Attendance\Events\OvertimeRequestSubmitted;
use App\Modules\Attendance\Listeners\NotifyOnOvertimeDecided;
use App\Modules\Attendance\Listeners\NotifyOnOvertimeSubmitted;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Observers\OvertimeRequestRealtimeObserver;
use App\Modules\Attendance\Observers\HolidayRealtimeObserver;
use App\Modules\Attendance\Observers\AttendanceRealtimeObserver;
use App\Modules\Attendance\Observers\ShiftRealtimeObserver;
use App\Modules\Attendance\Observers\ShiftAssignmentRealtimeObserver;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Services\RolePermissionCacheGeneration;
use App\Modules\Auth\Observers\EmployeeAccessScopeRealtimeObserver;
use App\Modules\B2B\Events\SupplierInvoiceSubmitted;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Listeners\EmailSupplierOnInvoiceSubmitted;
use App\Modules\B2B\Listeners\EmailSupplierOnPurchaseOrderCancelled;
use App\Modules\B2B\Observers\DeliveryScheduleRealtimeObserver;
use App\Modules\B2B\Services\SupplierPortalDispatchGateway;
use App\Modules\B2B\Services\PortalContractPdfProcessor;
use App\Modules\B2B\Services\SystemPdfTkProcessor;
use App\Modules\CRM\Events\ComplaintNcrRequested;
use App\Modules\CRM\Models\Complaint8DReport;
use App\Modules\CRM\Events\CustomerComplaintUpdated;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Listeners\AutoSpawn8DOnNcrRecurrence;
use App\Modules\CRM\Listeners\CreateNcrOnComplaintRequested;
use App\Modules\CRM\Listeners\EmailCustomerOnComplaintUpdated;
use App\Modules\CRM\Listeners\EmailCustomerOnSalesOrderConfirmed;
use App\Modules\CRM\Listeners\NotifyOnSalesOrderConfirmed;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\CustomerAgreementDocument;
use App\Modules\CRM\Models\CustomerProductWarrantyOverride;
use App\Modules\CRM\Models\CustomerProductWarrantyOption;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Observers\Complaint8DReportRealtimeObserver;
use App\Modules\CRM\Observers\CustomerComplaintRealtimeObserver;
use App\Modules\CRM\Observers\CustomerSubresourceRealtimeObserver;
use App\Modules\CRM\Observers\ProductRealtimeObserver;
use App\Modules\CRM\Observers\PriceAgreementRealtimeObserver;
use App\Modules\CRM\Observers\PriceRequestRealtimeObserver;
use App\Modules\CRM\Models\PriceRequest;
use App\Modules\CRM\Models\SalesOrderAgreement;
use App\Modules\CRM\Observers\SalesOrderAgreementRealtimeObserver;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Services\PricingReviewService;
use App\Modules\CRM\Services\SalesOrderSupplyService;
use App\Modules\Dashboard\Observers\BadgeInvalidationObserver;
use App\Modules\Dashboard\Observers\DashboardCacheInvalidationObserver;
use App\Modules\Dashboard\Models\KpiDefinition;
use App\Modules\Dashboard\Models\KpiSnapshot;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\Forecasting\Observers\DemandForecastRealtimeObserver;
use App\Modules\HR\Events\ClearanceFullySigned;
use App\Modules\HR\Models\Training;
use App\Modules\HR\Events\EmployeeCreated;
use App\Modules\HR\Events\SeparationInitiated;
use App\Modules\HR\Exports\EmployeeMasterExport;
use App\Modules\HR\Listeners\AutoProvisionUserOnEmployeeHire;
use App\Modules\HR\Listeners\DeactivateAccountOnClearanceComplete;
use App\Modules\HR\Listeners\InitializeLeaveBalances;
use App\Modules\HR\Listeners\NotifyOnSeparationInitiated;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmployeeSkill;
use App\Modules\HR\Models\EmployeeOnboarding;
use App\Modules\HR\Models\Skill;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\HR\Models\SalaryAdjustment;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\ApplicationNote;
use App\Modules\HR\Observers\ClearanceRealtimeObserver;
use App\Modules\HR\Observers\EmployeeRealtimeObserver;
use App\Modules\HR\Observers\EmployeeTrainingRealtimeObserver;
use App\Modules\HR\Observers\EmployeeDocumentsRealtimeObserver;
use App\Modules\HR\Observers\EmployeeSkillRealtimeObserver;
use App\Modules\HR\Observers\SkillRealtimeObserver;
use App\Modules\HR\Observers\ProfileUpdateRequestRealtimeObserver;
use App\Modules\HR\Observers\SalaryAdjustmentApprovalRecordRealtimeObserver;
use App\Modules\HR\Observers\SalaryAdjustmentRealtimeObserver;
use App\Modules\HR\Observers\TrainingCatalogRealtimeObserver;
use App\Modules\HR\Observers\DepartmentRealtimeObserver;
use App\Modules\HR\Observers\PositionRealtimeObserver;
use App\Modules\HR\Observers\JobPostingRealtimeObserver;
use App\Modules\HR\Observers\JobApplicationRealtimeObserver;
use App\Modules\HR\Observers\ApplicationInterviewRealtimeObserver;
use App\Modules\HR\Observers\ApplicationNoteRealtimeObserver;
use App\Modules\HR\Observers\EmployeeOnboardingRealtimeObserver;
use App\Modules\HR\Observers\EmployeeAccountOnboardingRealtimeObserver;
use App\Modules\Inventory\Events\GoodsReceiptNoteAccepted;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Inventory\Events\LowStockPrCreated;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Events\StockReservationChanged;
use App\Modules\Inventory\Events\StockMovementGlPostingRequested;
use App\Modules\Inventory\Listeners\CheckReorderPoint;
use App\Modules\Inventory\Listeners\CreateDraftGrnOnPoSent;
use App\Modules\Inventory\Listeners\NotifyOnGrnReceived;
use App\Modules\Inventory\Listeners\NotifyOnLowStockPrCreated;
use App\Modules\Inventory\Listeners\PostStockMovementToGlOnRequested;
use App\Modules\Inventory\Listeners\VerifyCoaOnIncomingQcPass;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\MaterialHandover;
use App\Modules\Inventory\Observers\MaterialHandoverRealtimeObserver;
use App\Modules\Inventory\Observers\MaterialReviewRecordRealtimeObserver;
use App\Modules\Inventory\Observers\MaterialIssueSlipRealtimeObserver;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCostReview;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Models\TransferOrder;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Observers\GoodsReceiptNoteRealtimeObserver;
use App\Modules\Inventory\Observers\GrnItemRealtimeObserver;
use App\Modules\Inventory\Observers\InventoryItemCatalogRealtimeObserver;
use App\Modules\Inventory\Observers\ItemRealtimeObserver;
use App\Modules\Inventory\Observers\StockCountItemRealtimeObserver;
use App\Modules\Inventory\Observers\StockCountSessionRealtimeObserver;
use App\Modules\Inventory\Observers\StockAdjustmentRealtimeObserver;
use App\Modules\Inventory\Observers\ItemCostReviewRealtimeObserver;
use App\Modules\Inventory\Observers\WarehouseLayoutRealtimeObserver;
use App\Modules\Inventory\Observers\TransferOrderRealtimeObserver;
use App\Modules\Inventory\Services\PublishInventoryStockChange;
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
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Observers\LeaveRequestRealtimeObserver;
use App\Modules\Leave\Observers\LeaveTypeRealtimeObserver;
use App\Modules\Loans\Events\LoanDecided;
use App\Modules\Loans\Events\LoanSubmitted;
use App\Modules\Loans\Listeners\NotifyOnLoanDecided;
use App\Modules\Loans\Listeners\NotifyOnLoanSubmitted;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Models\LoanPayment;
use App\Modules\Loans\Observers\EmployeeLoanRealtimeObserver;
use App\Modules\Loans\Observers\LoanApprovalRecordRealtimeObserver;
use App\Modules\Loans\Observers\LoanPaymentRealtimeObserver;
use App\Modules\Maintenance\Events\MaintenanceWorkOrderCreated;
use App\Modules\Maintenance\Events\PreventiveMaintenanceGenerationRequested;
use App\Modules\Maintenance\Listeners\GeneratePreventiveMaintenanceOnRequested;
use App\Modules\Maintenance\Listeners\NotifyMaintenanceWorkOrder;
use App\Modules\Maintenance\Models\MaintenanceLog;
use App\Modules\Maintenance\Models\MaintenancePartRequest;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWindow;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Models\SparePartUsage;
use App\Modules\Maintenance\Observers\MaintenanceScheduleRealtimeObserver;
use App\Modules\Maintenance\Observers\MaintenanceWorkOrderChildRealtimeObserver;
use App\Modules\Maintenance\Observers\MaintenanceWorkOrderRealtimeObserver;
use App\Modules\MRP\Events\BomPricingReviewRequested;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\MRP\Events\MrpReplanRequested;
use App\Modules\MRP\Listeners\QueueMrpOnOutgoingInspectionFailed;
use App\Modules\MRP\Listeners\QueueMrpOnReplanRequested;
use App\Modules\MRP\Listeners\QueueMrpOnSalesOrderConfirmed;
use App\Modules\MRP\Listeners\QueueMrpOnStockMovementCompleted;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\MRP\Models\MrpRun;
use App\Modules\MRP\Observers\BomRealtimeObserver;
use App\Modules\MRP\Observers\MachineRealtimeObserver;
use App\Modules\MRP\Observers\MoldRealtimeObserver;
use App\Modules\MRP\Observers\MrpPlanRealtimeObserver;
use App\Modules\MRP\Observers\MrpRunRealtimeObserver;
use App\Modules\Payroll\Events\PayrollComputationRequested;
use App\Modules\Payroll\Events\PayrollGlPostingRequested;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Listeners\EmailPayslipPdfOnPayrollFinalized;
use App\Modules\Payroll\Listeners\GenerateBankFileOnPayrollFinalized;
use App\Modules\Payroll\Listeners\NotifyEmployeesOnPayrollFinalized;
use App\Modules\Payroll\Listeners\PostPayrollToGlOnRequested;
use App\Modules\Payroll\Listeners\RunPayrollComputationOnRequested;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\DeMinimisBenefit;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\DisbursementProof;
use App\Modules\Payroll\Observers\PayrollAdjustmentRealtimeObserver;
use App\Modules\Payroll\Observers\DeMinimisBenefitRealtimeObserver;
use App\Modules\Payroll\Observers\PayrollPeriodRealtimeObserver;
use App\Modules\Payroll\Observers\DisbursementProofRealtimeObserver;
use App\Modules\Production\Events\MachineBreakdownDetected;
use App\Modules\Production\Events\ProductionReceiptRequested;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Events\WorkOrderQualityResolved;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Listeners\AutoCloseResolvedWorkOrder;
use App\Modules\Production\Listeners\CreateProductionReceiptOnOutputRequested;
use App\Modules\Production\Listeners\HandleMachineBreakdown;
use App\Modules\Production\Listeners\NotifyOnMachineBreakdown;
use App\Modules\Production\Listeners\NotifyOnWorkOrderCompleted;
use App\Modules\Production\Listeners\NotifyOnWorkOrderPaused;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\LateProductionReport;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Production\Models\WorkOrderCrewMember;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Models\WorkOrderDefect;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Observers\ProductionScheduleRealtimeObserver;
use App\Modules\Production\Observers\LateProductionReportRealtimeObserver;
use App\Modules\Production\Observers\ProductionStaffingObserver;
use App\Modules\Production\Observers\WoOperationRealtimeObserver;
use App\Modules\Production\Observers\WorkOrderAssignmentRealtimeObserver;
use App\Modules\Production\Observers\WorkOrderSchedulingDiagnosticRealtimeObserver;
use App\Modules\Purchasing\Contracts\SupplierDispatchGateway;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use App\Modules\Purchasing\Events\PurchaseOrderCancelled;
use App\Modules\Purchasing\Events\PurchaseOrderSent;
use App\Modules\Purchasing\Events\PurchaseOrderSubmitted;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
use App\Modules\Purchasing\Events\RfqLifecycleEvent;
use App\Modules\Purchasing\Events\SupplierPerformanceComputed;
use App\Modules\Purchasing\Listeners\AlertOnSupplierDeterioration;
use App\Modules\Purchasing\Listeners\CloseSupplierDispatchOnPurchaseOrderCancelled;
use App\Modules\Purchasing\Listeners\ConsolidatePurchaseOrders;
use App\Modules\Purchasing\Listeners\DeliverRfqLifecycleNotification;
use App\Modules\Purchasing\Listeners\NotifyOnPurchaseOrderApproved;
use App\Modules\Purchasing\Listeners\NotifyOnPurchaseOrderSubmitted;
use App\Modules\Purchasing\Listeners\NotifyOnPurchaseRequestApproved;
use App\Modules\Purchasing\Listeners\PrepareSupplierDispatch;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Models\SupplierPerformanceSnapshot;
use App\Modules\Purchasing\Models\SupplierItemListing;
use App\Modules\Purchasing\Observers\ProcurementChainCacheObserver;
use App\Modules\Quality\Events\InspectionFailed;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Events\NcrRecurrenceLinked;
use App\Modules\Quality\Events\OutgoingQuantityReleased;
use App\Modules\Quality\Listeners\AcceptGrnOnIncomingQcPass;
use App\Modules\Quality\Listeners\CreateDeliveryDraftOnQcPass;
use App\Modules\Quality\Listeners\NotifyOnInspectionFailed;
use App\Modules\Quality\Listeners\RejectGRNOnQcFail;
use App\Modules\Quality\Listeners\TriggerIncomingQC;
use App\Modules\Quality\Listeners\TriggerInProcessQC;
use App\Modules\Quality\Listeners\TriggerOutgoingQC;
use App\Modules\Quality\Models\CalibrationRecord;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionDefectivePiece;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionPieceReading;
use App\Modules\Quality\Models\InspectionResultAuthor;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Models\ItemQualityPlan;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NcrTemplate;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Models\PpapElement;
use App\Modules\Quality\Models\PpapSubmission;
use App\Modules\Quality\Observers\InspectionRealtimeObserver;
use App\Modules\Quality\Observers\InspectionResultAuthorRealtimeObserver;
use App\Modules\Quality\Observers\InspectionSpecItemRealtimeObserver;
use App\Modules\Quality\Observers\ItemQualityPlanRealtimeObserver;
use App\Modules\Quality\Observers\NcrActionRealtimeObserver;
use App\Modules\Quality\Observers\NcrRealtimeObserver;
use App\Modules\Quality\Observers\PpapElementRealtimeObserver;
use App\Modules\Quality\Observers\QualityRecordRealtimeObserver;
use App\Modules\ReturnManagement\Events\ReturnInspectionRequested;
use App\Modules\ReturnManagement\Events\ReturnRequestUpdated;
use App\Modules\ReturnManagement\Listeners\CompleteCustomerReplacement;
use App\Modules\ReturnManagement\Listeners\CreateReturnInspectionOnRequested;
use App\Modules\ReturnManagement\Listeners\EmailPartyOnReturnRequestUpdated;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Models\ReturnCaseAttachment;
use App\Modules\ReturnManagement\Models\ReturnCaseEvent as ReturnCaseTimelineEvent;
use App\Modules\ReturnManagement\Models\ReturnCaseLine;
use App\Modules\ReturnManagement\Models\ReturnCaseReceiptAllocation;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\ReturnManagement\Observers\ReturnCaseAttachmentRealtimeObserver;
use App\Modules\ReturnManagement\Observers\ReturnCaseEventRealtimeObserver;
use App\Modules\ReturnManagement\Observers\ReturnCaseLineRealtimeObserver;
use App\Modules\ReturnManagement\Observers\ReturnCaseRealtimeObserver;
use App\Modules\ReturnManagement\Observers\ReturnCaseReceiptAllocationRealtimeObserver;
use App\Modules\ReturnManagement\Observers\ReturnRequestRealtimeObserver;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use App\Modules\SupplyChain\Events\DeliveryInvoiceRequested;
use App\Modules\SupplyChain\Listeners\CreateDraftInvoiceOnDeliveryInvoiceRequested;
use App\Modules\SupplyChain\Listeners\EmailCustomerOnDeliveryConfirmed;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Models\Container;
use App\Modules\SupplyChain\Models\ShipmentDocument;
use App\Modules\SupplyChain\Models\ShipmentLandedCost;
use App\Modules\SupplyChain\Models\ShipmentLot;
use App\Modules\SupplyChain\Models\Vehicle;
use App\Modules\SupplyChain\Observers\ShipmentComponentRealtimeObserver;
use App\Modules\SupplyChain\Observers\ShipmentRealtimeObserver;
use App\Modules\SupplyChain\Observers\ShipmentLotRealtimeObserver;
use App\Modules\SupplyChain\Observers\DeliveryVehicleRealtimeObserver;
use App\Modules\SupplyChain\Observers\VehicleRealtimeObserver;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class, fn ($app) => new SettingsService);
        $this->app->singleton(EmailDeliveryLogService::class);
        $this->app->singleton(ScheduleTickFailureTracker::class);
        $this->app->singleton(SchedulerExecutionLedger::class);
        $this->app->singleton(RealtimeQueueTelemetry::class);
        $this->app->bind(CacheKeyAuditReader::class, RedisCacheKeyAuditReader::class);
        $this->app->bind(RetiredGenerationCacheKeyReader::class, RedisCacheKeyAuditReader::class);
        $this->app->bind(SupplierDispatchGateway::class, SupplierPortalDispatchGateway::class);
        $this->app->singleton(PortalContractPdfProcessor::class, fn ($app) => new SystemPdfTkProcessor(
            (string) $app['config']->get('pdftk.binary', '/usr/local/bin/pdftk'),
        ));
        $this->app->scoped(SalesOrderSupplyService::class);
        $this->app->scoped(RolePermissionCacheGeneration::class);
    }

    public function boot(): void
    {
        $createStandardizedPusher = static fn ($app, array $config, ?int $maxRequestSize = null): StandardizedPusherBroadcaster => new StandardizedPusherBroadcaster(
            $app->make(BroadcastManager::class)->pusher($config),
            (bool) ($config['jsonp'] ?? false),
            $maxRequestSize,
        );
        Broadcast::extend('reverb', static fn ($app, array $config): StandardizedPusherBroadcaster => $createStandardizedPusher(
            $app,
            $config,
            max(1, (int) $app['config']->get('reverb.servers.reverb.max_request_size', 10_000)),
        ));
        Broadcast::extend('pusher', $createStandardizedPusher);

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
        Queue::createPayloadUsing(static function ($connection, $queue): array {
            // RedisQueue passes its internal `queues:{name}` key to payload
            // hooks, even though JobQueued/JobProcessing report `{name}`.
            // Normalize that key here so only the two realtime lanes carry
            // the high precision enqueue timestamp.
            $queueName = is_string($queue) && str_starts_with($queue, 'queues:')
                ? substr($queue, strlen('queues:'))
                : $queue;

            return in_array($queueName, ['high', 'broadcasts'], true)
                ? ['realtime_queue_enqueued_at' => microtime(true)]
                : [];
        });
        AuditLog::observe(RecordAuthActivity::class);
        AuditLog::observe(AdminAuditLogRealtimeObserver::class);
        BackupOperation::observe(BackupOperationRealtimeObserver::class);
        ChainListenerRun::observe(ChainListenerRunRealtimeObserver::class);
        ImportBatch::observe(ImportBatchRealtimeObserver::class);
        Document::observe(EmployeeDocumentsRealtimeObserver::class);
        EmployeeDocument::observe(EmployeeDocumentsRealtimeObserver::class);
        EmployeeSkill::observe(EmployeeSkillRealtimeObserver::class);
        Skill::observe(SkillRealtimeObserver::class);
        ActivityEvent::observe(ActivityEventRealtimeObserver::class);
        Event::listen('*', [RecordActivityFromEvent::class, 'handleWildcard']);
        Event::listen(MigrationsEnded::class, static function (MigrationsEnded $event): void {
            if (! app(RolePermissionCacheGeneration::class)->invalidateAfterMigrations()) {
                throw new \RuntimeException('Role-permission cache namespace could not be rotated after database migrations.');
            }
        });
        Event::listen(CommandFinished::class, [QueueRestartSignalPolicy::class, 'handle']);
        Event::listen(ScheduledTaskFailed::class, [ScheduleTickFailureTracker::class, 'record']);
        Event::listen(ScheduledTaskStarting::class, [SchedulerExecutionLedger::class, 'recordTaskStarting']);
        Event::listen(ScheduledTaskFinished::class, [SchedulerExecutionLedger::class, 'recordTaskFinished']);
        Event::listen(ScheduledTaskFailed::class, [SchedulerExecutionLedger::class, 'recordTaskFailed']);
        Event::listen(JobProcessing::class, [ChainListenerRunService::class, 'markProcessing']);
        Event::listen(JobProcessing::class, [RealtimeQueueTelemetry::class, 'started']);
        Event::listen(JobQueued::class, [RealtimeQueueTelemetry::class, 'queued']);
        Event::listen(JobProcessed::class, [ChainListenerRunService::class, 'markProcessed']);
        Event::listen(JobProcessed::class, [RealtimeQueueTelemetry::class, 'processed']);
        Event::listen(JobExceptionOccurred::class, [ChainListenerRunService::class, 'markRetrying']);
        Event::listen(JobExceptionOccurred::class, [RealtimeQueueTelemetry::class, 'processed']);
        Event::listen(JobFailed::class, [ChainListenerRunService::class, 'markFailed']);
        Event::listen(JobProcessing::class, [EmailDeliveryLogService::class, 'beginJob']);
        Event::listen(JobProcessed::class, [EmailDeliveryLogService::class, 'endJob']);
        Event::listen(JobExceptionOccurred::class, [EmailDeliveryLogService::class, 'markFailed']);
        Event::listen(JobExceptionOccurred::class, [EmailDeliveryLogService::class, 'endJob']);
        Event::listen(MessageSending::class, [EmailDeliveryLogService::class, 'beginMessage']);
        Event::listen(MessageSent::class, [EmailDeliveryLogService::class, 'markSent']);
        Event::listen(MessageSending::class, static function (MessageSending $event): void {
            app(NonProductionEmailGuard::class)->protect($event->message);

            $branding = app(EmailBrandingService::class);
            foreach ([
                'ogami-logo@ogami' => $branding->logoPath(),
                'ogami-logo-dark@ogami' => $branding->logoDarkPath(),
            ] as $contentId => $path) {
                if ($path === null) {
                    continue;
                }

                foreach ($event->message->getAttachments() as $attachment) {
                    if ($attachment instanceof DataPart && $attachment->getContentId() === $contentId) {
                        continue 2;
                    }
                }

                $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/svg+xml';
                $event->message->addPart(
                    (new DataPart(new File($path), basename($path), $mime))
                        ->asInline()
                        ->setContentId($contentId)
                );
            }
        });

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
        // Register every source-backed dashboard cache invalidator before any
        // domain observer can stage its after-commit realtime/outbox callback.
        // A client refetch triggered by that signal must see the new cache
        // generation as soon as the transaction is committed.
        foreach ([
            Alert::class, ApprovalDelegation::class, AuditLog::class, LoginHistory::class, Role::class, User::class,
            ApprovalRecord::class,
            Account::class, AccountingPeriod::class, Bill::class, Budget::class,
            BudgetLineItem::class, BudgetTransfer::class, CreditNote::class,
            Department::class, Customer::class, FiscalYear::class, Invoice::class, JournalEntry::class,
            Vendor::class, PayrollPeriod::class, Asset::class, Machine::class,
            MachineDowntime::class, Mold::class, MrpRun::class, MaintenanceSchedule::class,
            WorkOrderOutput::class, WorkOrder::class, SalesOrder::class,
            Attendance::class, Holiday::class, Clearance::class, SalaryAdjustment::class,
            CustomerComplaint::class, Complaint8DReport::class, DemandForecast::class, Delivery::class, Employee::class,
            EmployeeLeaveBalance::class, EmployeeLoan::class, GoodsReceiptNote::class,
            Inspection::class, InspectionDefectivePiece::class, InspectionMeasurement::class,
            InspectionPieceReading::class, LeaveRequest::class, LoanPayment::class,
            MaintenanceWorkOrder::class, MaterialIssueSlip::class, MrpPlan::class,
            Item::class, NonConformanceReport::class, OvertimeRequest::class, Payroll::class,
            PriceRequest::class, ProfileUpdateRequest::class, PurchaseOrder::class,
            PurchaseOrderItem::class, PurchaseRequest::class, PurchaseRequestItem::class,
            ApprovedSupplier::class, TransferOrder::class,
            Warehouse::class, WarehouseZone::class, WarehouseLocation::class,
            ReturnRequest::class, Shipment::class, SupplierPerformanceSnapshot::class,
            Product::class, WorkOrderDefect::class, DefectType::class, DeliveryProof::class,
            KpiDefinition::class, KpiSnapshot::class,
            GrnItem::class, StockMovement::class, ReturnRequestItem::class,
            ChainListenerRun::class,
        ] as $dashboardSourceModel) {
            $dashboardSourceModel::observe(DashboardCacheInvalidationObserver::class);
        }

        DemandForecast::observe(DemandForecastRealtimeObserver::class);

        Inspection::observe(InspectionRealtimeObserver::class);
        CustomerComplaint::observe(CustomerComplaintRealtimeObserver::class);
        Complaint8DReport::observe(Complaint8DReportRealtimeObserver::class);
        Product::observe(ProductRealtimeObserver::class);
        PriceAgreement::observe(PriceAgreementRealtimeObserver::class);
        PriceRequest::observe(PriceRequestRealtimeObserver::class);
        SalesOrderAgreement::observe(SalesOrderAgreementRealtimeObserver::class);
        Holiday::observe(HolidayRealtimeObserver::class);
        Attendance::observe(AttendanceRealtimeObserver::class);
        Shift::observe(ShiftRealtimeObserver::class);
        EmployeeShiftAssignment::observe(ShiftAssignmentRealtimeObserver::class);
        InspectionResultAuthor::observe(InspectionResultAuthorRealtimeObserver::class);
        PayrollPeriod::observe(PayrollPeriodRealtimeObserver::class);
        DisbursementProof::observe(DisbursementProofRealtimeObserver::class);
        ApprovalDelegation::observe(ApprovalDelegationAccessRealtimeObserver::class);
        Role::observe(AdminRoleRealtimeObserver::class);
        User::observe(AdminUserRealtimeObserver::class);
        PayrollAdjustment::observe(PayrollAdjustmentRealtimeObserver::class);
        DeMinimisBenefit::observe(DeMinimisBenefitRealtimeObserver::class);
        ItemQualityPlan::observe(ItemQualityPlanRealtimeObserver::class);
        GoodsReceiptNote::observe(GoodsReceiptNoteRealtimeObserver::class);
        GrnItem::observe(GrnItemRealtimeObserver::class);
        NonConformanceReport::observe(NcrRealtimeObserver::class);
        NcrAction::observe(NcrActionRealtimeObserver::class);
        // Keep metric-cache rotation ahead of the durable realtime callback.
        ReturnRequest::observe(ReturnRequestRealtimeObserver::class);
        ReturnCase::observe(ReturnCaseRealtimeObserver::class);
        ReturnCaseTimelineEvent::observe(ReturnCaseEventRealtimeObserver::class);
        ReturnCaseLine::observe(ReturnCaseLineRealtimeObserver::class);
        ReturnCaseAttachment::observe(ReturnCaseAttachmentRealtimeObserver::class);
        ReturnCaseReceiptAllocation::observe(ReturnCaseReceiptAllocationRealtimeObserver::class);
        Clearance::observe(ClearanceRealtimeObserver::class);
        Department::observe(DepartmentRealtimeObserver::class);
        Position::observe(PositionRealtimeObserver::class);
        MaintenanceWorkOrder::observe(MaintenanceWorkOrderRealtimeObserver::class);
        MaintenanceSchedule::observe(MaintenanceScheduleRealtimeObserver::class);
        MaintenanceLog::observe(MaintenanceWorkOrderChildRealtimeObserver::class);
        MaintenancePartRequest::observe(MaintenanceWorkOrderChildRealtimeObserver::class);
        SparePartUsage::observe(MaintenanceWorkOrderChildRealtimeObserver::class);
        MaintenanceWindow::observe(MaintenanceWorkOrderChildRealtimeObserver::class);
        MachineDowntime::observe(MaintenanceWorkOrderChildRealtimeObserver::class);
        Account::observe(AccountStatementCacheObserver::class);
        Account::observe(AccountRealtimeObserver::class);
        AccountingPeriod::observe(AccountingPeriodRealtimeObserver::class);
        BudgetTransfer::observe(BudgetTransferRealtimeObserver::class);
        Budget::observe(BudgetRealtimeObserver::class);
        FiscalYear::observe(FiscalYearRealtimeObserver::class);
        CreditNote::observe(CreditNoteRealtimeObserver::class);
        Customer::observe(CustomerRealtimeObserver::class);
        CustomerAgreementDocument::observe(CustomerSubresourceRealtimeObserver::class);
        CustomerProductWarrantyOverride::observe(CustomerSubresourceRealtimeObserver::class);
        CustomerProductWarrantyOption::observe(CustomerSubresourceRealtimeObserver::class);
        Vendor::observe(VendorRealtimeObserver::class);
        ScheduledExport::observe(ScheduledExportRealtimeObserver::class);
        JournalEntry::observe(JournalEntryObserver::class);
        foreach ([PurchaseRequest::class, PurchaseOrder::class, GoodsReceiptNote::class, Bill::class] as $procurementAggregateModel) {
            $procurementAggregateModel::observe(ProcurementChainCacheObserver::class);
        }
        foreach ([Attendance::class, LeaveRequest::class,
            Employee::class, User::class] as $staffingModel) {
            $staffingModel::observe(ProductionStaffingObserver::class);
        }
        LeaveRequest::observe(LeaveRequestRealtimeObserver::class);
        OvertimeRequest::observe(OvertimeRequestRealtimeObserver::class);
        Employee::observe(EmployeeAccessScopeRealtimeObserver::class);
        Employee::observe(EmployeeRealtimeObserver::class);
        EmployeeTraining::observe(EmployeeTrainingRealtimeObserver::class);
        SalaryAdjustment::observe(SalaryAdjustmentRealtimeObserver::class);
        ApprovalRecord::observe(SalaryAdjustmentApprovalRecordRealtimeObserver::class);
        EmployeeLoan::observe(EmployeeLoanRealtimeObserver::class);
        LoanPayment::observe(LoanPaymentRealtimeObserver::class);
        ApprovalRecord::observe(LoanApprovalRecordRealtimeObserver::class);
        Training::observe(TrainingCatalogRealtimeObserver::class);
        LeaveType::observe(LeaveTypeRealtimeObserver::class);
        LateProductionReport::observe(LateProductionReportRealtimeObserver::class);
        StockCountSession::observe(StockCountSessionRealtimeObserver::class);
        StockCountItem::observe(StockCountItemRealtimeObserver::class);
        StockAdjustment::observe(StockAdjustmentRealtimeObserver::class);
        ItemCostReview::observe(ItemCostReviewRealtimeObserver::class);
        MaterialReviewRecord::observe(MaterialReviewRecordRealtimeObserver::class);
        MaterialIssueSlip::observe(MaterialIssueSlipRealtimeObserver::class);
        Item::observe(ItemRealtimeObserver::class);
        Warehouse::observe(WarehouseLayoutRealtimeObserver::class);
        WarehouseZone::observe(WarehouseLayoutRealtimeObserver::class);
        WarehouseLocation::observe(WarehouseLayoutRealtimeObserver::class);
        TransferOrder::observe(TransferOrderRealtimeObserver::class);
        foreach ([ItemCategory::class, ItemUomConversion::class, Uom::class] as $itemCatalogModel) {
            $itemCatalogModel::observe(InventoryItemCatalogRealtimeObserver::class);
        }

        // Polish Task S2 (real-time): bump the badge cache version + broadcast
        // BadgesChanged on any write to a model that backs a sidebar badge, so
        // connected clients refetch their counts instantly. StockLevel is
        // intentionally excluded — its write frequency would flood the channel.
        // Stock-ledger and reservation outbox paths publish one content-free
        // signal and rotate stock-derived cache generations instead.
        //
        // 2026-08-08 scope pass: added the models backing the widened badge set
        // (inquiries, complaints, inspections, GRN, MRB holds, shipments, MRP
        // plans, returns, invoices, bills) plus Item, EmployeeTraining and
        // SalesOrder whose badges previously only refreshed on the TTL/poll.
        foreach ([
            ApprovalRecord::class,
            Alert::class,
            PurchaseRequest::class,
            PurchaseOrder::class,
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
            CalibrationRecord::class,
            NcrAction::class,
            GoodsReceiptNote::class,
            MaterialReviewRecord::class,
            Shipment::class,
            Vehicle::class,
            MrpPlan::class,
            ReturnRequest::class,
            Invoice::class,
            InvoicePaymentSubmission::class,
            Bill::class,
            Budget::class,
            BudgetTransfer::class,
            Employee::class,
            Attendance::class,
            Clearance::class,
            // Custom ApprovalBoardService queues don't use approval_records.
            // Their state changes still need to invalidate each viewer's board.
            StockAdjustment::class,
            ItemCostReview::class,
            PayrollAdjustment::class,
            EmployeeLoan::class,
            SupplierItemListing::class,
        ] as $badgeModel) {
            $badgeModel::observe(BadgeInvalidationObserver::class);
        }

        CalibrationRecord::observe(QualityRecordRealtimeObserver::class);
        PpapSubmission::observe(QualityRecordRealtimeObserver::class);
        PpapElement::observe(PpapElementRealtimeObserver::class);
        InspectionSpec::observe(QualityRecordRealtimeObserver::class);
        InspectionSpecItem::observe(InspectionSpecItemRealtimeObserver::class);
        NcrTemplate::observe(QualityRecordRealtimeObserver::class);
        Shipment::observe(ShipmentRealtimeObserver::class);
        Container::observe(ShipmentComponentRealtimeObserver::class);
        ShipmentDocument::observe(ShipmentComponentRealtimeObserver::class);
        ShipmentLandedCost::observe(ShipmentComponentRealtimeObserver::class);
        ShipmentLot::observe(ShipmentLotRealtimeObserver::class);
        Vehicle::observe(VehicleRealtimeObserver::class);
        // Keep the outbox enqueue callback after the dashboard/badge cache
        // generation observers above, so a peer refetch cannot race stale data.
        ProfileUpdateRequest::observe(ProfileUpdateRequestRealtimeObserver::class);
        JobPosting::observe(JobPostingRealtimeObserver::class);
        JobApplication::observe(JobApplicationRealtimeObserver::class);
        ApplicationInterview::observe(ApplicationInterviewRealtimeObserver::class);
        ApplicationNote::observe(ApplicationNoteRealtimeObserver::class);
        EmployeeOnboarding::observe(EmployeeOnboardingRealtimeObserver::class);
        User::observe(EmployeeAccountOnboardingRealtimeObserver::class);
        DeliverySchedule::observe(DeliveryScheduleRealtimeObserver::class);
        Delivery::observe(DeliveryVehicleRealtimeObserver::class);
        WorkOrderCrew::observe(WorkOrderAssignmentRealtimeObserver::class);
        WorkOrderCrewMember::observe(WorkOrderAssignmentRealtimeObserver::class);
        WorkOrder::observe(WorkOrderSchedulingDiagnosticRealtimeObserver::class);
        MaterialHandover::observe(MaterialHandoverRealtimeObserver::class);
        WoOperation::observe(WoOperationRealtimeObserver::class);
        ProductionSchedule::observe(ProductionScheduleRealtimeObserver::class);
        Machine::observe(MachineRealtimeObserver::class);
        Mold::observe(MoldRealtimeObserver::class);
        Bom::observe(BomRealtimeObserver::class);
        MrpPlan::observe(MrpPlanRealtimeObserver::class);
        MrpRun::observe(MrpRunRealtimeObserver::class);

        // Sprint 5: low-stock auto-replenishment listener.
        Event::listen(StockMovementCompleted::class, [CheckReorderPoint::class, 'handle']);
        Event::listen(StockMovementCompleted::class, [PublishInventoryStockChange::class, 'handle']);
        Event::listen(StockReservationChanged::class, [PublishInventoryStockChange::class, 'handleReservation']);

        // Task 8: notify purchasing officer and warehouse staff when an auto-PR is created for a low-stock item.
        Event::listen(LowStockPrCreated::class, [NotifyOnLowStockPrCreated::class, 'handle']);

        // Automatic MRP is initiated only after the durable business event is
        // published. The queued job coalesces repeated requests and performs
        // both demand planning and finite-capacity scheduling.
        Event::listen(SalesOrderConfirmed::class, [QueueMrpOnSalesOrderConfirmed::class, 'handle']);
        Event::listen(StockMovementCompleted::class, [QueueMrpOnStockMovementCompleted::class, 'handle']);
        Event::listen(InspectionFailed::class, [QueueMrpOnOutgoingInspectionFailed::class, 'handle']);
        Event::listen(BomPricingReviewRequested::class, [PricingReviewService::class, 'handle']);
        Event::listen(MrpReplanRequested::class, [QueueMrpOnReplanRequested::class, 'handle']);

        // Sprint 6 Task 56: machine breakdown / restoration handling.
        Event::listen(MachineStatusChanged::class, [HandleMachineBreakdown::class, 'handle']);

        // Task 7: notify maintenance techs and production manager on breakdown.
        Event::listen(MachineBreakdownDetected::class, [NotifyOnMachineBreakdown::class, 'handle']);

        // Task A4: Notify Finance when a delivery is confirmed (draft invoice ready).
        Event::listen(DeliveryConfirmed::class, [NotifyFinanceOnDeliveryConfirmed::class, 'handle']);
        Event::listen(DeliveryConfirmed::class, [EmailCustomerOnDeliveryConfirmed::class, 'handle']);
        Event::listen(DeliveryConfirmed::class, [CompleteCustomerReplacement::class, 'handle']);
        Event::listen(InvoiceFinalized::class, [EmailCustomerOnInvoiceFinalized::class, 'handle']);
        Event::listen(OfficialReceiptIssued::class, [EmailCustomerOnOfficialReceiptIssued::class, 'handle']);
        Event::listen(CreditNoteFinalized::class, [EmailPartyOnCreditNoteFinalized::class, 'handle']);
        Event::listen(CustomerComplaintUpdated::class, [EmailCustomerOnComplaintUpdated::class, 'handle']);
        Event::listen(ReturnRequestUpdated::class, [EmailPartyOnReturnRequestUpdated::class, 'handle']);
        Event::listen(ReturnRequestUpdated::class, [CompleteCustomerReplacement::class, 'handle']);
        Event::listen(SupplierInvoiceSubmitted::class, [EmailSupplierOnInvoiceSubmitted::class, 'handle']);
        Event::listen(BudgetActualsSyncRequested::class, [RunBudgetActualsSyncOnRequested::class, 'handle']);
        Event::listen(MaintenanceWorkOrderCreated::class, [NotifyMaintenanceWorkOrder::class, 'handle']);
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
        Event::listen(SalesOrderConfirmed::class, [EmailCustomerOnSalesOrderConfirmed::class, 'handle']);
        // ADV7 — auto-trigger in-process QC on WO start.
        Event::listen(WorkOrderStatusChanged::class, [TriggerInProcessQC::class,          'handle']);
        Event::listen(WorkOrderStatusChanged::class, [NotifyOnWorkOrderPaused::class, 'handle']);
        Event::listen(WorkOrderCompleted::class, [TriggerOutgoingQC::class,           'handle']);
        Event::listen(WorkOrderQualityResolved::class, [AutoCloseResolvedWorkOrder::class, 'handle']);
        Event::listen(WorkOrderCompleted::class, [NotifyOnWorkOrderCompleted::class,  'handle']);
        Event::listen(InspectionPassed::class, [CreateDeliveryDraftOnQcPass::class, 'handle']);
        Event::listen(OutgoingQuantityReleased::class, [CreateDeliveryDraftOnQcPass::class, 'handle']);
        Event::listen(InspectionPassed::class, [AcceptGrnOnIncomingQcPass::class,      'handle']);
        // OGAMI-005 — an incoming pass with a CoA on file verifies the CoA
        // (trace §7.7: the column previously had no writer at all).
        Event::listen(InspectionPassed::class, [VerifyCoaOnIncomingQcPass::class,      'handle']);

        // C2 Procure-to-Pay
        Event::listen(GoodsReceiptNoteCreated::class, [TriggerIncomingQC::class,                'handle']);
        Event::listen(GoodsReceiptNoteCreated::class, [NotifyOnGrnReceived::class,              'handle']);
        // Auto-bill chain — GRN accepted → draft supplier bill for review.
        Event::listen(GoodsReceiptNoteAccepted::class, [PricingReviewService::class, 'receiptCostChanged']);
        Event::listen(GoodsReceiptNoteAccepted::class, [AutoCreateBillOnGrnAccepted::class,      'handle']);
        Event::listen(PurchaseRequestApproved::class, [NotifyOnPurchaseRequestApproved::class,  'handle']);
        Event::listen(PurchaseRequestApproved::class, [ConsolidatePurchaseOrders::class,        'handle']);
        Event::listen(RfqLifecycleEvent::class, [DeliverRfqLifecycleNotification::class, 'handle']);
        Event::listen(PurchaseOrderApproved::class, [NotifyOnPurchaseOrderApproved::class,    'handle']);
        Event::listen(PurchaseOrderSubmitted::class, [NotifyOnPurchaseOrderSubmitted::class, 'handle']);
        Event::listen(PurchaseOrderApproved::class, [PrepareSupplierDispatch::class,           'handle']);
        Event::listen(PurchaseOrderCancelled::class, [CloseSupplierDispatchOnPurchaseOrderCancelled::class, 'handle']);
        Event::listen(PurchaseOrderCancelled::class, [EmailSupplierOnPurchaseOrderCancelled::class, 'handle']);
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
