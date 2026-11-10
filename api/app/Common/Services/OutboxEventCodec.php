<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Events\AdminBackupsChanged;
use App\Common\Events\CacheGroupInvalidated;
use App\Common\Events\ChainListenerRunsChanged;
use App\Common\Events\ChainStepAdvanced;
use App\Common\Events\DashboardLayoutChanged;
use App\Common\Events\DatabaseRestoreCompleted;
use App\Common\Events\ImportBatchChanged;
use App\Common\Events\NotificationsPruned;
use App\Common\Events\PermissionOverrideChanged;
use App\Common\Events\PortalNotificationChanged;
use App\Common\Events\ScheduledExportChanged;
use App\Common\Events\ToleratesNewerModelState;
use App\Common\Events\UserDisplayPreferencesChanged;
use App\Common\Events\UserNotificationChanged;
use App\Common\Events\UserNotificationCreated;
use App\Common\Events\UserNotificationPreferencesChanged;
use App\Modules\Accounting\Events\AccountChanged;
use App\Modules\Accounting\Events\AccountingPeriodChanged;
use App\Modules\Accounting\Events\BudgetActualsSyncRequested;
use App\Modules\Accounting\Events\BudgetChanged;
use App\Modules\Accounting\Events\BudgetTransferChanged;
use App\Modules\Accounting\Events\CreditNoteChanged;
use App\Modules\Accounting\Events\CustomerChanged;
use App\Modules\Accounting\Events\JournalEntryChanged;
use App\Modules\Accounting\Events\VendorChanged;
use App\Modules\Admin\Events\ActivityFeedChanged;
use App\Modules\Admin\Events\AdminAuditLogsChanged;
use App\Modules\Admin\Events\AdminRoleChanged;
use App\Modules\Admin\Events\AdminSessionsChanged;
use App\Modules\Admin\Events\AdminUserChanged;
use App\Modules\Admin\Events\ApprovalDelegationChanged;
use App\Modules\Admin\Events\RolePermissionsChanged;
use App\Modules\Admin\Events\SettingsChanged;
use App\Modules\Admin\Events\UserAccessChanged;
use App\Modules\Assets\Events\AssetChanged;
use App\Modules\Assets\Events\MonthlyDepreciationRequested;
use App\Modules\Attendance\Events\AttendanceChanged;
use App\Modules\Attendance\Events\HolidayChanged;
use App\Modules\Attendance\Events\OvertimeRequestChanged;
use App\Modules\Attendance\Events\OvertimeRequestDecided;
use App\Modules\Attendance\Events\OvertimeRequestSubmitted;
use App\Modules\Attendance\Events\ShiftAssignmentChanged;
use App\Modules\Attendance\Events\ShiftChanged;
use App\Modules\Auth\Events\UserInviteChanged;
use App\Modules\B2B\Events\B2bDeliveryScheduleChanged;
use App\Modules\B2B\Events\PortalAccessChanged;
use App\Modules\B2B\Events\PortalAccountAccessRevoked;
use App\Modules\CRM\Events\ComplaintNcrRequested;
use App\Modules\CRM\Events\CustomerCatalogChanged;
use App\Modules\CRM\Events\CustomerComplaintChanged;
use App\Modules\CRM\Events\PriceAgreementChanged;
use App\Modules\CRM\Events\PriceRequestChanged;
use App\Modules\CRM\Events\ProductChanged;
use App\Modules\CRM\Events\SalesOrderAdvanceReleased;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\Dashboard\Events\AdminDashboardChanged;
use App\Modules\Dashboard\Events\BadgesChanged;
use App\Modules\Dashboard\Events\ChainBottlenecksChanged;
use App\Modules\Dashboard\Events\DashboardWidgetDataChanged;
use App\Modules\Dashboard\Events\EmployeeDashboardChanged;
use App\Modules\Dashboard\Events\FinanceDashboardChanged;
use App\Modules\Dashboard\Events\HrDashboardChanged;
use App\Modules\Dashboard\Events\KpiSnapshotChanged;
use App\Modules\Dashboard\Events\PlantManagerDashboardChanged;
use App\Modules\Dashboard\Events\PpcDashboardChanged;
use App\Modules\Dashboard\Events\PurchasingDashboardChanged;
use App\Modules\Dashboard\Events\QualityDashboardChanged;
use App\Modules\Dashboard\Events\WarehouseDashboardChanged;
use App\Modules\DSS\Events\DssChanged;
use App\Modules\Forecasting\Events\DemandForecastChanged;
use App\Modules\HR\Events\ClearanceChanged;
use App\Modules\HR\Events\ClearanceFullySigned;
use App\Modules\HR\Events\DepartmentChanged;
use App\Modules\HR\Events\EmployeeChanged;
use App\Modules\HR\Events\EmployeeCreated;
use App\Modules\HR\Events\EmployeeDocumentsChanged;
use App\Modules\HR\Events\EmployeeOnboardingChanged;
use App\Modules\HR\Events\EmployeeSkillChanged;
use App\Modules\HR\Events\EmployeeTrainingChanged;
use App\Modules\HR\Events\PositionChanged;
use App\Modules\HR\Events\ProfileUpdateRequestChanged;
use App\Modules\HR\Events\RecruitmentChanged;
use App\Modules\HR\Events\SalaryAdjustmentChanged;
use App\Modules\HR\Events\SeparationInitiated;
use App\Modules\HR\Events\SkillChanged;
use App\Modules\HR\Events\TrainingCatalogChanged;
use App\Modules\Inventory\Events\GoodsReceiptNoteAccepted;
use App\Modules\Inventory\Events\GoodsReceiptNoteChanged;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Inventory\Events\InventoryStockChanged;
use App\Modules\Inventory\Events\ItemChanged;
use App\Modules\Inventory\Events\ItemCostReviewChanged;
use App\Modules\Inventory\Events\LowStockPrCreated;
use App\Modules\Inventory\Events\MaterialIssueSlipChanged;
use App\Modules\Inventory\Events\MaterialReviewRecordChanged;
use App\Modules\Inventory\Events\StockAdjustmentChanged;
use App\Modules\Inventory\Events\StockCountChanged;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Events\StockMovementGlPostingRequested;
use App\Modules\Inventory\Events\StockReservationChanged;
use App\Modules\Inventory\Events\TransferOrderChanged;
use App\Modules\Inventory\Events\WarehouseLayoutChanged;
use App\Modules\Landing\Events\ContactInquiryChanged;
use App\Modules\Leave\Events\LeaveBalanceChanged;
use App\Modules\Leave\Events\LeaveRequestApproved;
use App\Modules\Leave\Events\LeaveRequestChanged;
use App\Modules\Leave\Events\LeaveRequestPendingHR;
use App\Modules\Leave\Events\LeaveRequestRejected;
use App\Modules\Leave\Events\LeaveRequestSubmitted;
use App\Modules\Leave\Events\LeaveTypeChanged;
use App\Modules\Leave\Events\YearEndLeaveProcessingRequested;
use App\Modules\Loans\Events\EmployeeLoanChanged;
use App\Modules\Loans\Events\LoanDecided;
use App\Modules\Loans\Events\LoanSubmitted;
use App\Modules\Maintenance\Events\MaintenanceScheduleChanged;
use App\Modules\Maintenance\Events\MaintenanceWorkOrderChanged;
use App\Modules\Maintenance\Events\MaintenanceWorkOrderCreated;
use App\Modules\Maintenance\Events\PreventiveMaintenanceGenerationRequested;
use App\Modules\MRP\Events\BomChanged;
use App\Modules\MRP\Events\BomPricingReviewRequested;
use App\Modules\MRP\Events\MachineChanged;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\MRP\Events\MoldChanged;
use App\Modules\MRP\Events\MoldShotLimitNearing;
use App\Modules\MRP\Events\MoldShotLimitReached;
use App\Modules\MRP\Events\MrpPlanChanged;
use App\Modules\MRP\Events\MrpPlanGenerated;
use App\Modules\MRP\Events\MrpReplanRequested;
use App\Modules\MRP\Events\MrpRunChanged;
use App\Modules\Payroll\Events\DeMinimisBenefitChanged;
use App\Modules\Payroll\Events\GovernmentContributionTableChanged;
use App\Modules\Payroll\Events\PayrollAdjustmentChanged;
use App\Modules\Payroll\Events\PayrollAnomalyChanged;
use App\Modules\Payroll\Events\PayrollComputationRequested;
use App\Modules\Payroll\Events\PayrollGlPostingRequested;
use App\Modules\Payroll\Events\PayrollPeriodChanged;
use App\Modules\Payroll\Events\PayrollPeriodDisbursed;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Events\PayrollPeriodVoided;
use App\Modules\Production\Events\AssignedWorkOrderChanged;
use App\Modules\Production\Events\LateProductionReportChanged;
use App\Modules\Production\Events\MachineBreakdownDetected;
use App\Modules\Production\Events\ProductionReceiptRequested;
use App\Modules\Production\Events\ProductionRoutingChanged;
use App\Modules\Production\Events\ProductionScheduleChanged;
use App\Modules\Production\Events\WorkOrderAssignmentChanged;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Events\WorkOrderOperationChanged;
use App\Modules\Production\Events\WorkOrderOutputRecorded;
use App\Modules\Production\Events\WorkOrderQualityResolved;
use App\Modules\Production\Events\WorkOrderSchedulingDiagnosticChanged;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use App\Modules\Purchasing\Events\PurchaseOrderCancelled;
use App\Modules\Purchasing\Events\PurchaseOrderSent;
use App\Modules\Purchasing\Events\PurchaseOrderSubmitted;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
use App\Modules\Purchasing\Events\RequestForQuoteChanged;
use App\Modules\Purchasing\Events\RfqLifecycleEvent;
use App\Modules\Purchasing\Events\SupplierCatalogChanged;
use App\Modules\Purchasing\Events\SupplierPerformanceChanged;
use App\Modules\Purchasing\Events\SupplierPerformanceComputed;
use App\Modules\Purchasing\Events\SupplierRfqChanged;
use App\Modules\Quality\Events\InspectionChanged;
use App\Modules\Quality\Events\InspectionFailed;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Events\NcrChanged;
use App\Modules\Quality\Events\NcrRecurrenceLinked;
use App\Modules\Quality\Events\OutgoingQuantityReleased;
use App\Modules\Quality\Events\QualityRecordChanged;
use App\Modules\ReturnManagement\Events\ReturnCaseChanged;
use App\Modules\ReturnManagement\Events\ReturnInspectionRequested;
use App\Modules\ReturnManagement\Events\ReturnRequestChanged;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use App\Modules\SupplyChain\Events\DeliveryInvoiceRequested;
use App\Modules\SupplyChain\Events\ShipmentChanged;
use App\Modules\SupplyChain\Events\ShipmentLotChanged;
use App\Modules\SupplyChain\Events\VehicleChanged;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use RuntimeException;

/**
 * Encode and hydrate the small, explicit event set that crosses module
 * boundaries. The allow-list is intentional: an event type read from the
 * database must never become an arbitrary class-instantiation primitive.
 */
class OutboxEventCodec
{
    /** @var list<class-string> */
    private const SUPPORTED_EVENTS = [
        ChainStepAdvanced::class,
        CacheGroupInvalidated::class,
        AdminBackupsChanged::class,
        ChainListenerRunsChanged::class,
        PermissionOverrideChanged::class,
        ScheduledExportChanged::class,
        ImportBatchChanged::class,
        UserInviteChanged::class,
        UserNotificationPreferencesChanged::class,
        UserDisplayPreferencesChanged::class,
        DashboardLayoutChanged::class,
        ContactInquiryChanged::class,
        NotificationsPruned::class,
        PortalNotificationChanged::class,
        UserNotificationChanged::class,
        UserNotificationCreated::class,
        DatabaseRestoreCompleted::class,
        BadgesChanged::class,
        AdminDashboardChanged::class,
        ChainBottlenecksChanged::class,
        DashboardWidgetDataChanged::class,
        FinanceDashboardChanged::class,
        EmployeeDashboardChanged::class,
        KpiSnapshotChanged::class,
        HrDashboardChanged::class,
        PurchasingDashboardChanged::class,
        WarehouseDashboardChanged::class,
        QualityDashboardChanged::class,
        PlantManagerDashboardChanged::class,
        PpcDashboardChanged::class,
        DemandForecastChanged::class,
        ActivityFeedChanged::class,
        AdminAuditLogsChanged::class,
        AdminSessionsChanged::class,
        SalesOrderConfirmed::class,
        SalesOrderAdvanceReleased::class,
        ComplaintNcrRequested::class,
        CustomerComplaintChanged::class,
        CustomerCatalogChanged::class,
        B2bDeliveryScheduleChanged::class,
        PortalAccountAccessRevoked::class,
        PortalAccessChanged::class,
        ProductChanged::class,
        PriceAgreementChanged::class,
        PriceRequestChanged::class,
        WorkOrderCompleted::class,
        WorkOrderAssignmentChanged::class,
        AssignedWorkOrderChanged::class,
        WorkOrderOperationChanged::class,
        WorkOrderQualityResolved::class,
        WorkOrderStatusChanged::class,
        WorkOrderOutputRecorded::class,
        WorkOrderSchedulingDiagnosticChanged::class,
        ProductionReceiptRequested::class,
        MachineChanged::class,
        MoldChanged::class,
        BomChanged::class,
        MachineStatusChanged::class,
        MachineBreakdownDetected::class,
        ProductionScheduleChanged::class,
        ProductionRoutingChanged::class,
        LateProductionReportChanged::class,
        PurchaseRequestApproved::class,
        RfqLifecycleEvent::class,
        RequestForQuoteChanged::class,
        SupplierRfqChanged::class,
        SupplierCatalogChanged::class,
        PurchaseOrderApproved::class,
        PurchaseOrderSent::class,
        PurchaseOrderSubmitted::class,
        PurchaseOrderCancelled::class,
        GoodsReceiptNoteCreated::class,
        GoodsReceiptNoteChanged::class,
        ItemChanged::class,
        ItemCostReviewChanged::class,
        MaterialReviewRecordChanged::class,
        MaterialIssueSlipChanged::class,
        GoodsReceiptNoteAccepted::class,
        LowStockPrCreated::class,
        InventoryStockChanged::class,
        StockMovementCompleted::class,
        StockReservationChanged::class,
        StockCountChanged::class,
        StockAdjustmentChanged::class,
        StockMovementGlPostingRequested::class,
        WarehouseLayoutChanged::class,
        TransferOrderChanged::class,
        MaintenanceWorkOrderChanged::class,
        MaintenanceScheduleChanged::class,
        ClearanceChanged::class,
        EmployeeChanged::class,
        SalaryAdjustmentChanged::class,
        EmployeeTrainingChanged::class,
        EmployeeDocumentsChanged::class,
        EmployeeSkillChanged::class,
        EmployeeOnboardingChanged::class,
        ProfileUpdateRequestChanged::class,
        SkillChanged::class,
        RecruitmentChanged::class,
        DepartmentChanged::class,
        PositionChanged::class,
        TrainingCatalogChanged::class,
        ReturnInspectionRequested::class,
        ReturnCaseChanged::class,
        ReturnRequestChanged::class,
        InspectionPassed::class,
        InspectionChanged::class,
        NcrChanged::class,
        QualityRecordChanged::class,
        OutgoingQuantityReleased::class,
        InspectionFailed::class,
        NcrRecurrenceLinked::class,
        DeliveryConfirmed::class,
        DeliveryInvoiceRequested::class,
        ShipmentChanged::class,
        ShipmentLotChanged::class,
        VehicleChanged::class,
        EmployeeCreated::class,
        SeparationInitiated::class,
        ClearanceFullySigned::class,
        PayrollPeriodFinalized::class,
        PayrollComputationRequested::class,
        PayrollGlPostingRequested::class,
        PayrollPeriodDisbursed::class,
        PayrollPeriodChanged::class,
        PayrollAdjustmentChanged::class,
        PayrollAnomalyChanged::class,
        DeMinimisBenefitChanged::class,
        GovernmentContributionTableChanged::class,
        PayrollPeriodVoided::class,
        MaintenanceWorkOrderCreated::class,
        PreventiveMaintenanceGenerationRequested::class,
        RolePermissionsChanged::class,
        ApprovalDelegationChanged::class,
        AdminUserChanged::class,
        AdminRoleChanged::class,
        SettingsChanged::class,
        DssChanged::class,
        UserAccessChanged::class,
        MonthlyDepreciationRequested::class,
        AssetChanged::class,
        OvertimeRequestSubmitted::class,
        OvertimeRequestDecided::class,
        OvertimeRequestChanged::class,
        HolidayChanged::class,
        ShiftChanged::class,
        ShiftAssignmentChanged::class,
        AttendanceChanged::class,
        BudgetActualsSyncRequested::class,
        AccountingPeriodChanged::class,
        CustomerChanged::class,
        AccountChanged::class,
        BudgetTransferChanged::class,
        BudgetChanged::class,
        CreditNoteChanged::class,
        JournalEntryChanged::class,
        VendorChanged::class,
        LeaveRequestSubmitted::class,
        LeaveRequestChanged::class,
        LeaveBalanceChanged::class,
        LeaveTypeChanged::class,
        LeaveRequestPendingHR::class,
        LeaveRequestApproved::class,
        LeaveRequestRejected::class,
        YearEndLeaveProcessingRequested::class,
        LoanSubmitted::class,
        LoanDecided::class,
        EmployeeLoanChanged::class,
        SupplierPerformanceChanged::class,
        SupplierPerformanceComputed::class,
        MoldShotLimitNearing::class,
        MoldShotLimitReached::class,
        MrpPlanChanged::class,
        MrpPlanGenerated::class,
        MrpReplanRequested::class,
        MrpRunChanged::class,
        BomPricingReviewRequested::class,
    ];

    /** @return array{event_type: class-string, payload: array<string, mixed>} */
    public function encode(object $event): array
    {
        $eventType = $event::class;
        $this->assertSupported($eventType);

        $constructor = (new ReflectionClass($event))->getConstructor();
        if ($constructor === null) {
            return ['event_type' => $eventType, 'payload' => []];
        }

        $payload = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (! property_exists($event, $name)) {
                throw new RuntimeException("Outbox event {$eventType} has no public property for {$name}.");
            }

            $payload[$name] = $this->encodeValue($event->{$name});
        }

        return ['event_type' => $eventType, 'payload' => $payload];
    }

    public function decode(string $eventType, array $payload): object
    {
        $this->assertSupported($eventType);
        $reflection = new ReflectionClass($eventType);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (! array_key_exists($name, $payload)) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();

                    continue;
                }
                throw new RuntimeException("Outbox payload for {$eventType} is missing {$name}.");
            }

            $arguments[] = $this->decodeValue($payload[$name], $parameter->getType(), $parameter);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /** @return array<string, mixed> */
    public function fingerprintPayload(object $event): array
    {
        return $this->encode($event)['payload'];
    }

    /** @return list<class-string> */
    public static function supportedEventTypes(): array
    {
        return self::SUPPORTED_EVENTS;
    }

    private function assertSupported(string $eventType): void
    {
        if (! in_array($eventType, self::SUPPORTED_EVENTS, true)) {
            throw new RuntimeException("Event {$eventType} is not registered for durable publication.");
        }
    }

    private function encodeValue(mixed $value): mixed
    {
        if ($value instanceof Model) {
            if (! $value->exists || $value->getKey() === null) {
                throw new RuntimeException('Cannot put an unsaved model into the event outbox.');
            }

            return [
                '__type' => 'model',
                'class' => $value::class,
                'id' => (string) $value->getKey(),
                'version' => $value->getRawOriginal('updated_at'),
            ];
        }

        if ($value instanceof BackedEnum) {
            return [
                '__type' => 'enum',
                'class' => $value::class,
                'value' => $value->value,
            ];
        }

        if ($value instanceof DateTimeInterface) {
            return [
                '__type' => 'datetime',
                'class' => $value::class,
                'value' => $value->format(DateTimeInterface::ATOM),
            ];
        }

        if (is_array($value)) {
            $encoded = [];
            foreach ($value as $key => $item) {
                $encoded[$key] = $this->encodeValue($item);
            }

            return $encoded;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        throw new RuntimeException('Unsupported value in event outbox payload: '.get_debug_type($value));
    }

    private function decodeValue(mixed $value, ?ReflectionType $expectedType, ReflectionParameter $parameter): mixed
    {
        if (! is_array($value) || ! isset($value['__type'])) {
            if (is_array($value)) {
                return array_map(fn (mixed $item): mixed => $this->decodeValue($item, null, $parameter), $value);
            }

            return $value;
        }

        return match ($value['__type']) {
            'model' => $this->decodeModel($value, $expectedType, $parameter),
            'enum' => $this->decodeEnum($value, $expectedType),
            'datetime' => new \DateTimeImmutable((string) $value['value']),
            default => throw new RuntimeException('Unknown outbox payload marker.'),
        };
    }

    /** @param array{__type: string, class: mixed, id: mixed, version?: mixed} $value */
    private function decodeModel(array $value, ?ReflectionType $expectedType, ReflectionParameter $parameter): Model
    {
        $class = $value['class'] ?? null;
        if (! is_string($class) || ! is_a($class, Model::class, true)) {
            throw new RuntimeException('Outbox payload contains an invalid model type.');
        }

        $expectedClass = $this->namedClass($expectedType);
        if ($expectedClass !== null && ! is_a($class, $expectedClass, true)) {
            throw new RuntimeException("Outbox model {$class} does not match constructor parameter {$parameter->getName()}.");
        }

        $model = $class::query()->find($value['id'] ?? null);
        if (! $model) {
            throw new RuntimeException("Outbox model {$class}#{$value['id']} no longer exists.");
        }

        // Model markers carry the version that was published. Rehydrating the
        // current row without checking it turns a stale retry/replay into a
        // false event containing a later state. Old rows without a marker are
        // still readable; all newly encoded rows include it.
        // A fact event (ToleratesNewerModelState) accepts a newer row: its
        // listeners re-read and guard on current state.
        if (array_key_exists('version', $value) && $value['version'] !== null) {
            $publishedAt = Carbon::parse((string) $value['version']);
            $currentAt = Carbon::parse((string) $model->getRawOriginal('updated_at'));
            $toleratesNewer = $parameter->getDeclaringClass()?->implementsInterface(ToleratesNewerModelState::class) ?? false;
            if (! $publishedAt->equalTo($currentAt) && ! ($toleratesNewer && $currentAt->greaterThan($publishedAt))) {
                throw new RuntimeException("Outbox model {$class}#{$value['id']} changed after publication.");
            }
        }

        return $model;
    }

    /** @param array{__type: string, class: mixed, value: mixed} $value */
    private function decodeEnum(array $value, ?ReflectionType $expectedType): BackedEnum
    {
        $class = $value['class'] ?? null;
        if (! is_string($class) || ! enum_exists($class) || ! is_a($class, BackedEnum::class, true)) {
            throw new RuntimeException('Outbox payload contains an invalid enum type.');
        }

        $expectedClass = $this->namedClass($expectedType);
        if ($expectedClass !== null && $expectedClass !== $class) {
            throw new RuntimeException('Outbox enum does not match its constructor parameter.');
        }

        return $class::from($value['value']);
    }

    private function namedClass(?ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType && ! $type->isBuiltin()
            ? $type->getName()
            : null;
    }
}
