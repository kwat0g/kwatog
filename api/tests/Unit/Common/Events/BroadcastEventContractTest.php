<?php

declare(strict_types=1);

namespace Tests\Unit\Common\Events;

use App\Common\Events\AdminBackupsChanged;
use App\Common\Events\CacheGroupInvalidated;
use App\Common\Events\ChainListenerRunsChanged;
use App\Common\Events\ChainStepAdvanced;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use App\Common\Events\DashboardLayoutChanged;
use App\Common\Events\DatabaseRestoreCompleted;
use App\Common\Events\ImportBatchChanged;
use App\Common\Events\NotificationsPruned;
use App\Common\Events\PermissionOverrideChanged;
use App\Common\Events\PortalNotificationChanged;
use App\Common\Events\ScheduledExportChanged;
use App\Common\Events\UserDisplayPreferencesChanged;
use App\Common\Events\UserNotificationChanged;
use App\Common\Events\UserNotificationCreated;
use App\Common\Events\UserNotificationPreferencesChanged;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Accounting\Events\AccountChanged;
use App\Modules\Accounting\Events\AccountingPeriodChanged;
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
use App\Modules\Attendance\Events\AttendanceChanged;
use App\Modules\Attendance\Events\HolidayChanged;
use App\Modules\Attendance\Events\OvertimeRequestChanged;
use App\Modules\Attendance\Events\ShiftAssignmentChanged;
use App\Modules\Attendance\Events\ShiftChanged;
use App\Modules\Auth\Events\UserInviteChanged;
use App\Modules\B2B\Events\B2bDeliveryScheduleChanged;
use App\Modules\B2B\Events\PortalAccessChanged;
use App\Modules\B2B\Events\PortalAccountAccessRevoked;
use App\Modules\CRM\Events\CustomerCatalogChanged;
use App\Modules\CRM\Events\CustomerComplaintChanged;
use App\Modules\CRM\Events\PriceAgreementChanged;
use App\Modules\CRM\Events\PriceRequestChanged;
use App\Modules\CRM\Events\ProductChanged;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Dashboard\Events\AdminDashboardChanged;
use App\Modules\Dashboard\Events\BadgesChanged;
use App\Modules\Dashboard\Events\ChainBottlenecksChanged;
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
use App\Modules\HR\Events\DepartmentChanged;
use App\Modules\HR\Events\EmployeeChanged;
use App\Modules\HR\Events\EmployeeDocumentsChanged;
use App\Modules\HR\Events\EmployeeOnboardingChanged;
use App\Modules\HR\Events\EmployeeSkillChanged;
use App\Modules\HR\Events\EmployeeTrainingChanged;
use App\Modules\HR\Events\PositionChanged;
use App\Modules\HR\Events\ProfileUpdateRequestChanged;
use App\Modules\HR\Events\RecruitmentChanged;
use App\Modules\HR\Events\SalaryAdjustmentChanged;
use App\Modules\HR\Events\SkillChanged;
use App\Modules\HR\Events\TrainingCatalogChanged;
use App\Modules\Inventory\Events\GoodsReceiptNoteChanged;
use App\Modules\Inventory\Events\InventoryStockChanged;
use App\Modules\Inventory\Events\ItemChanged;
use App\Modules\Inventory\Events\ItemCostReviewChanged;
use App\Modules\Inventory\Events\MaterialIssueSlipChanged;
use App\Modules\Inventory\Events\MaterialReviewRecordChanged;
use App\Modules\Inventory\Events\StockAdjustmentChanged;
use App\Modules\Inventory\Events\StockCountChanged;
use App\Modules\Inventory\Events\TransferOrderChanged;
use App\Modules\Inventory\Events\WarehouseLayoutChanged;
use App\Modules\Landing\Events\ContactInquiryChanged;
use App\Modules\Leave\Events\LeaveBalanceChanged;
use App\Modules\Leave\Events\LeaveRequestChanged;
use App\Modules\Leave\Events\LeaveTypeChanged;
use App\Modules\Loans\Events\EmployeeLoanChanged;
use App\Modules\Maintenance\Events\MaintenanceScheduleChanged;
use App\Modules\Maintenance\Events\MaintenanceWorkOrderChanged;
use App\Modules\MRP\Events\BomChanged;
use App\Modules\MRP\Events\MachineChanged;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\MRP\Events\MoldChanged;
use App\Modules\MRP\Events\MoldShotLimitNearing;
use App\Modules\MRP\Events\MoldShotLimitReached;
use App\Modules\MRP\Events\MrpPlanChanged;
use App\Modules\MRP\Events\MrpPlanGenerated;
use App\Modules\MRP\Events\MrpRunChanged;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Payroll\Events\DeMinimisBenefitChanged;
use App\Modules\Payroll\Events\GovernmentContributionTableChanged;
use App\Modules\Payroll\Events\PayrollAdjustmentChanged;
use App\Modules\Payroll\Events\PayrollAnomalyChanged;
use App\Modules\Payroll\Events\PayrollPeriodChanged;
use App\Modules\Payroll\Events\PayrollProgressEvent;
use App\Modules\Production\Events\AssignedWorkOrderChanged;
use App\Modules\Production\Events\LateProductionReportChanged;
use App\Modules\Production\Events\MachineBreakdownDetected;
use App\Modules\Production\Events\ProductionRoutingChanged;
use App\Modules\Production\Events\ProductionScheduleChanged;
use App\Modules\Production\Events\WorkOrderAssignmentChanged;
use App\Modules\Production\Events\WorkOrderOperationChanged;
use App\Modules\Production\Events\WorkOrderOutputRecorded;
use App\Modules\Production\Events\WorkOrderSchedulingDiagnosticChanged;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Purchasing\Events\RequestForQuoteChanged;
use App\Modules\Purchasing\Events\SupplierCatalogChanged;
use App\Modules\Purchasing\Events\SupplierPerformanceChanged;
use App\Modules\Purchasing\Events\SupplierRfqChanged;
use App\Modules\Quality\Events\InspectionChanged;
use App\Modules\Quality\Events\NcrChanged;
use App\Modules\Quality\Events\QualityRecordChanged;
use App\Modules\ReturnManagement\Events\ReturnCaseChanged;
use App\Modules\ReturnManagement\Events\ReturnRequestChanged;
use App\Modules\SupplyChain\Events\ShipmentChanged;
use App\Modules\SupplyChain\Events\ShipmentLotChanged;
use App\Modules\SupplyChain\Events\VehicleChanged;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class BroadcastEventContractTest extends TestCase
{
    /** @var list<class-string> */
    private const EVENTS = [
        SalesOrderConfirmed::class,
        MaintenanceWorkOrderChanged::class,
        MaintenanceScheduleChanged::class,
        ClearanceChanged::class,
        EmployeeChanged::class,
        SalaryAdjustmentChanged::class,
        DepartmentChanged::class,
        PositionChanged::class,
        WorkOrderAssignmentChanged::class,
        WorkOrderOperationChanged::class,
        WorkOrderOutputRecorded::class,
        WorkOrderStatusChanged::class,
        ProductionScheduleChanged::class,
        ProductionRoutingChanged::class,
        MrpPlanGenerated::class,
        MachineChanged::class,
        BomChanged::class,
        MrpPlanChanged::class,
        MrpRunChanged::class,
        MoldChanged::class,
        MoldShotLimitNearing::class,
        MoldShotLimitReached::class,
        PayrollProgressEvent::class,
        PayrollPeriodChanged::class,
        PayrollAdjustmentChanged::class,
        PayrollAnomalyChanged::class,
        DeMinimisBenefitChanged::class,
        GovernmentContributionTableChanged::class,
        BadgesChanged::class,
        PermissionOverrideChanged::class,
        PortalNotificationChanged::class,
        UserNotificationChanged::class,
        UserNotificationCreated::class,
        ChainStepAdvanced::class,
        CacheGroupInvalidated::class,
        AdminBackupsChanged::class,
        AdminSessionsChanged::class,
        ChainListenerRunsChanged::class,
        ChainBottlenecksChanged::class,
        InventoryStockChanged::class,
        WarehouseLayoutChanged::class,
        TransferOrderChanged::class,
        GoodsReceiptNoteChanged::class,
        RequestForQuoteChanged::class,
        SupplierCatalogChanged::class,
        InspectionChanged::class,
        NcrChanged::class,
        QualityRecordChanged::class,
        ShipmentLotChanged::class,
        ReturnRequestChanged::class,
        ReturnCaseChanged::class,
        SupplierPerformanceChanged::class,
        CustomerComplaintChanged::class,
        PriceAgreementChanged::class,
        ProductChanged::class,
        CustomerCatalogChanged::class,
        StockCountChanged::class,
        ItemChanged::class,
        MaterialReviewRecordChanged::class,
        MaterialIssueSlipChanged::class,
        LeaveTypeChanged::class,
        LeaveBalanceChanged::class,
        LeaveRequestChanged::class,
        CreditNoteChanged::class,
        BudgetTransferChanged::class,
        BudgetChanged::class,
        VendorChanged::class,
        JournalEntryChanged::class,
        AccountingPeriodChanged::class,
        CustomerChanged::class,
        AccountChanged::class,
        LateProductionReportChanged::class,
        AssignedWorkOrderChanged::class,
        DssChanged::class,
        AttendanceChanged::class,
        HolidayChanged::class,
        AssetChanged::class,
        EmployeeTrainingChanged::class,
        EmployeeDocumentsChanged::class,
        EmployeeSkillChanged::class,
        SkillChanged::class,
        ProfileUpdateRequestChanged::class,
        TrainingCatalogChanged::class,
        AdminUserChanged::class,
        AdminRoleChanged::class,
        SettingsChanged::class,
        RolePermissionsChanged::class,
        UserAccessChanged::class,
        ActivityFeedChanged::class,
        AdminAuditLogsChanged::class,
        HrDashboardChanged::class,
        AdminDashboardChanged::class,
        FinanceDashboardChanged::class,
        EmployeeDashboardChanged::class,
        PurchasingDashboardChanged::class,
        WarehouseDashboardChanged::class,
        QualityDashboardChanged::class,
        PlantManagerDashboardChanged::class,
        PpcDashboardChanged::class,
        DemandForecastChanged::class,
        ScheduledExportChanged::class,
        ImportBatchChanged::class,
        DatabaseRestoreCompleted::class,
        NotificationsPruned::class,
        UserDisplayPreferencesChanged::class,
        DashboardLayoutChanged::class,
        UserNotificationPreferencesChanged::class,
        ApprovalDelegationChanged::class,
        OvertimeRequestChanged::class,
        ShiftAssignmentChanged::class,
        ShiftChanged::class,
        UserInviteChanged::class,
        B2bDeliveryScheduleChanged::class,
        PortalAccessChanged::class,
        PortalAccountAccessRevoked::class,
        PriceRequestChanged::class,
        KpiSnapshotChanged::class,
        EmployeeOnboardingChanged::class,
        RecruitmentChanged::class,
        ItemCostReviewChanged::class,
        StockAdjustmentChanged::class,
        ContactInquiryChanged::class,
        EmployeeLoanChanged::class,
        WorkOrderSchedulingDiagnosticChanged::class,
        SupplierRfqChanged::class,
        ShipmentChanged::class,
        VehicleChanged::class,
    ];

    public function test_all_broadcast_events_are_after_commit_and_use_the_dedicated_queue(): void
    {
        foreach (self::EVENTS as $eventClass) {
            $this->assertTrue(is_subclass_of($eventClass, ShouldBroadcast::class), $eventClass);
            $this->assertTrue(is_subclass_of($eventClass, ShouldDispatchAfterCommit::class), $eventClass);
            $this->assertTrue(method_exists($eventClass, 'broadcastWith'), "{$eventClass} must whitelist its wire payload.");
            $this->assertContains(BroadcastsOnDedicatedQueue::class, class_uses($eventClass) ?: [], $eventClass);

            $source = file_get_contents((new ReflectionClass($eventClass))->getFileName());
            $this->assertMatchesRegularExpression('/new\s+PrivateChannel\s*\(/', $source, $eventClass);
            $this->assertDoesNotMatchRegularExpression('/new\s+Channel\s*\(/', $source, $eventClass);
            $broadcastMethod = new \ReflectionMethod($eventClass, 'broadcastWith');
            $methodSource = implode('', array_slice(
                file($broadcastMethod->getFileName()),
                $broadcastMethod->getStartLine() - 1,
                $broadcastMethod->getEndLine() - $broadcastMethod->getStartLine() + 1,
            ));
            $hasEventId = preg_match('/[\'\"]event_id[\'\"]\s*=>/', $methodSource) === 1
                || str_contains($methodSource, 'BroadcastEventEnvelope::refresh(');
            $this->assertTrue($hasEventId, "{$eventClass} must include a stable event ID in its broadcast payload.");

            $event = (new ReflectionClass($eventClass))->newInstanceWithoutConstructor();
            $this->assertSame('redis_broadcasts', $event->connection, $eventClass);
            $this->assertSame('redis_broadcasts', $event->broadcastConnection(), $eventClass);
            $this->assertSame('broadcasts', $event->broadcastQueue(), $eventClass);
            $this->assertSame([1, 5, 15], $event->backoff, $eventClass);
        }
    }

    public function test_broadcast_event_inventory_cannot_silently_drift(): void
    {
        $discoveredFiles = collect(File::allFiles(app_path()))
            ->filter(static function ($file): bool {
                $source = $file->getContents();

                return preg_match('/^\s*(?:final\s+)?class\s+\w+\s+implements\s+([^\{]+)\{/m', $source, $matches) === 1
                    && str_contains($matches[1], 'ShouldBroadcast');
            })
            ->map(static fn ($file): string => $file->getRealPath())
            ->sort()
            ->values()
            ->all();

        $listedFiles = collect(self::EVENTS)
            ->map(static fn (string $eventClass): string => (new ReflectionClass($eventClass))->getFileName())
            ->sort()
            ->values()
            ->all();

        $this->assertSame($discoveredFiles, $listedFiles);
    }

    public function test_machine_domain_events_do_not_create_duplicate_ui_broadcasts(): void
    {
        $this->assertFalse(is_subclass_of(MachineStatusChanged::class, ShouldBroadcast::class));
        $this->assertFalse(is_subclass_of(MachineBreakdownDetected::class, ShouldBroadcast::class));
        $this->assertTrue(is_subclass_of(MachineChanged::class, ShouldBroadcast::class));
    }

    public function test_purchasing_dashboard_signal_is_private_and_contains_no_source_data(): void
    {
        $event = new PurchasingDashboardChanged(
            eventId: 'purchasing-dashboard-event',
            timestamp: '2026-11-01T00:00:00.000000Z',
        );

        $this->assertSame(['private-dashboard.purchasing'], array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $event->broadcastOn(),
        ));
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'purchasing-dashboard-event',
            'timestamp' => '2026-11-01T00:00:00.000000Z',
        ], $event->broadcastWith());

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(PurchasingDashboardChanged::class, $decoded);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
    }

    public function test_overtime_refresh_signal_carries_only_ordering_and_refresh_metadata(): void
    {
        $event = new OvertimeRequestChanged(
            overtimeRequestHashId: 'overtime-hash',
            ownerUserHashId: 'owner-hash',
            action: 'status_changed',
            version: '2',
            eventId: 'overtime-event',
            occurredAt: '2026-11-03T00:00:00.000000Z',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'overtime-event',
            'entity_type' => 'overtime_request',
            'action' => 'status_changed',
            'version' => '2',
            'occurred_at' => '2026-11-03T00:00:00.000000Z',
        ], $event->broadcastWith());
    }

    public function test_legacy_refresh_signals_have_a_stable_event_id_in_the_wire_payload(): void
    {
        $events = [
            new SalesOrderConfirmed(new SalesOrder, [], null, 'sales-order-event'),
            new WorkOrderOutputRecorded(new WorkOrder, new WorkOrderOutput, [], 'work-order-output-event'),
            new WorkOrderStatusChanged(new WorkOrder, 'queued', 'running', eventId: 'work-order-status-event'),
            new MrpPlanGenerated(new MrpPlan, eventId: 'mrp-plan-event'),
            new MoldShotLimitNearing(new Mold, 'mold-nearing-event'),
            new MoldShotLimitReached(new Mold, 'mold-reached-event'),
            new BadgesChanged('badges-event'),
            new PermissionOverrideChanged(42, 'inventory.items.view', null, null, 'test', 'permission-override-event'),
            new UserNotificationCreated(42, 'notification-id', 'test.changed', 'notification-created-event'),
            new PortalNotificationChanged('customer', 'customer-user-hash', 'created', eventId: 'portal-notification-event'),
        ];

        foreach ($events as $event) {
            $payload = $event->broadcastWith();

            $this->assertSame($event->eventId, $payload['event_id'] ?? null, $event::class);
            $this->assertNotSame('', $event->eventId, $event::class);
        }

        $firstDefaultId = (new BadgesChanged)->eventId;
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $firstDefaultId);
        $this->assertNotSame($firstDefaultId, (new BadgesChanged)->eventId);

        $codec = app(OutboxEventCodec::class);
        foreach ([new BadgesChanged('badges-outbox-event'), new PermissionOverrideChanged(42, 'inventory.items.view', null, null, 'test', 'permission-outbox-event')] as $event) {
            $encoded = $codec->encode($event);
            $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
            $this->assertSame($event->eventId, $decoded->eventId, $event::class);

            // Pre-deployment outbox rows do not have the new optional constructor argument.
            unset($encoded['payload']['eventId']);
            $legacyDecoded = $codec->decode($encoded['event_type'], $encoded['payload']);
            $this->assertNotSame('', $legacyDecoded->eventId, $event::class);
        }
    }

    public function test_database_restore_signal_round_trips_through_the_outbox_codec(): void
    {
        $event = new DatabaseRestoreCompleted(
            eventId: 'restore-event-123',
            timestamp: '2026-11-04T00:00:00.000000Z',
        );

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);

        $this->assertSame(DatabaseRestoreCompleted::class, $encoded['event_type']);
        $this->assertSame($event->eventId, $decoded->eventId);
        $this->assertSame($event->timestamp, $decoded->timestamp);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
    }

    public function test_quality_dashboard_signal_is_private_and_contains_no_source_data(): void
    {
        $event = new QualityDashboardChanged(
            eventId: 'quality-dashboard-event',
            timestamp: '2026-11-01T00:00:00.000000Z',
        );

        $this->assertSame(['private-quality.dashboard'], array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $event->broadcastOn(),
        ));
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'quality-dashboard-event',
            'entity_type' => 'quality_dashboard',
            'timestamp' => '2026-11-01T00:00:00.000000Z',
        ], $event->broadcastWith());

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(QualityDashboardChanged::class, $decoded);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
    }

    public function test_plant_manager_dashboard_signal_is_private_and_contains_no_source_data(): void
    {
        $event = new PlantManagerDashboardChanged(
            eventId: 'plant-manager-dashboard-event',
            timestamp: '2026-11-01T00:00:00.000000Z',
        );

        $this->assertSame(['private-dashboard.plant_manager'], array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $event->broadcastOn(),
        ));
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'plant-manager-dashboard-event',
            'entity_type' => 'plant_manager_dashboard',
            'timestamp' => '2026-11-01T00:00:00.000000Z',
        ], $event->broadcastWith());

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(PlantManagerDashboardChanged::class, $decoded);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
    }

    public function test_ppc_dashboard_signal_is_private_and_contains_no_source_data(): void
    {
        $event = new PpcDashboardChanged(
            eventId: 'ppc-dashboard-event',
            timestamp: '2026-11-04T00:00:00.000000Z',
        );

        $this->assertSame(['private-dashboard.ppc'], array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $event->broadcastOn(),
        ));
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'ppc-dashboard-event',
            'entity_type' => 'ppc_dashboard',
            'timestamp' => '2026-11-04T00:00:00.000000Z',
        ], $event->broadcastWith());

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(PpcDashboardChanged::class, $decoded);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
    }

    public function test_finance_dashboard_signal_is_private_and_contains_no_source_data(): void
    {
        $event = new FinanceDashboardChanged(
            eventId: 'finance-dashboard-event',
            timestamp: '2026-11-04T00:00:00.000000Z',
        );

        $this->assertSame(['private-dashboard.finance'], array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $event->broadcastOn(),
        ));
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'finance-dashboard-event',
            'entity_type' => 'finance_dashboard',
            'timestamp' => '2026-11-04T00:00:00.000000Z',
        ], $event->broadcastWith());

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(FinanceDashboardChanged::class, $decoded);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
    }

    public function test_employee_dashboard_signal_targets_the_owner_or_linked_employee_audience(): void
    {
        $ownerEvent = new EmployeeDashboardChanged(
            employeeUserHashId: 'owner-hash',
            eventId: 'employee-dashboard-owner-event',
            timestamp: '2026-11-04T00:00:00.000000Z',
        );
        $globalEvent = new EmployeeDashboardChanged(
            eventId: 'employee-dashboard-global-event',
            timestamp: '2026-11-04T00:00:01.000000Z',
        );

        $this->assertSame(['private-dashboard.employee.user.owner-hash'], array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $ownerEvent->broadcastOn(),
        ));
        $this->assertSame(['private-dashboard.employee'], array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $globalEvent->broadcastOn(),
        ));
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'employee-dashboard-owner-event',
            'entity_type' => 'employee_dashboard',
            'timestamp' => '2026-11-04T00:00:00.000000Z',
        ], $ownerEvent->broadcastWith());

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($ownerEvent);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(EmployeeDashboardChanged::class, $decoded);
        $this->assertSame($ownerEvent->broadcastOn()[0]->name, $decoded->broadcastOn()[0]->name);
    }

    public function test_customer_signal_uses_private_channels_and_only_refresh_metadata(): void
    {
        $event = new CustomerChanged(
            customerHashId: 'customer-hash',
            action: 'updated',
            version: '2026-11-01T00:00:00.000000Z',
            actorHashId: 'actor-hash',
            eventId: 'customer-event',
            timestamp: '2026-11-01T00:00:01.000000Z',
        );

        $this->assertSame([
            'private-entity.customer.visible',
            'private-accounting.customer.customer-hash',
            'private-customer.profile.customer-hash',
        ], array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $event->broadcastOn(),
        ));
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'customer-event',
            'entity_type' => 'customer',
            'entity_id' => 'customer-hash',
            'action' => 'updated',
            'version' => '2026-11-01T00:00:00.000000Z',
            'actor_id' => 'actor-hash',
            'timestamp' => '2026-11-01T00:00:01.000000Z',
        ], $event->broadcastWith());
        $this->assertSame($event->broadcastWith(), app(OutboxEventCodec::class)
            ->decode(CustomerChanged::class, app(OutboxEventCodec::class)->encode($event)['payload'])
            ->broadcastWith());
    }

    public function test_customer_creation_batch_signal_is_list_only_and_omits_record_ids(): void
    {
        $event = new CustomerChanged(
            customerHashId: null,
            action: 'created',
            version: '2026-11-01T00:00:00.000000Z',
            actorHashId: 'actor-hash',
            isBatch: true,
            eventId: 'customer-batch-event',
            timestamp: '2026-11-01T00:00:01.000000Z',
        );

        $this->assertSame(['private-entity.customer.visible'], array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $event->broadcastOn(),
        ));
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'customer-batch-event',
            'entity_type' => 'customer',
            'action' => 'created',
            'version' => '2026-11-01T00:00:00.000000Z',
            'actor_id' => 'actor-hash',
            'timestamp' => '2026-11-01T00:00:01.000000Z',
        ], $event->broadcastWith());
        $this->assertSame($event->broadcastWith(), app(OutboxEventCodec::class)
            ->decode(CustomerChanged::class, app(OutboxEventCodec::class)->encode($event)['payload'])
            ->broadcastWith());
    }

    public function test_demand_forecast_signal_is_private_and_omits_record_identifiers(): void
    {
        $event = new DemandForecastChanged(
            forecastHashId: 'forecast-hash',
            action: 'updated',
            version: '2026-11-01T00:00:00.000000Z',
            eventId: 'forecast-event',
            timestamp: '2026-11-01T00:00:01.000000Z',
        );

        $this->assertSame([
            'private-entity.demand_forecast.visible',
            'private-dashboard.purchasing',
            'private-forecast.demand-forecast.forecast-hash',
        ], array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $event->broadcastOn(),
        ));
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'forecast-event',
            'entity_type' => 'demand_forecast',
            'action' => 'updated',
            'version' => '2026-11-01T00:00:00.000000Z',
            'timestamp' => '2026-11-01T00:00:01.000000Z',
        ], $event->broadcastWith());

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(DemandForecastChanged::class, $decoded);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
    }

    public function test_framework_queues_the_broadcast_after_commit_on_the_dedicated_connection(): void
    {
        Queue::fake();
        DB::beginTransaction();

        Event::dispatch(new UserNotificationCreated(1, 'notification-id', 'test.changed'));

        Queue::assertNothingPushed();
        DB::commit();

        Queue::assertPushedOn('broadcasts', BroadcastEvent::class);
        $this->assertSame([1, 5, 15], Queue::pushed(BroadcastEvent::class)->first()->backoff);
    }

    public function test_framework_selects_the_event_queue_connection_property(): void
    {
        $broadcastQueue = Mockery::mock(QueueContract::class);
        $broadcastQueue->shouldReceive('pushOn')
            ->once()
            ->with('broadcasts', Mockery::type(BroadcastEvent::class));

        $queueManager = Mockery::mock(QueueManager::class);
        $queueManager->shouldReceive('connection')
            ->once()
            ->with('redis_broadcasts')
            ->andReturn($broadcastQueue);

        $this->app->instance('queue', $queueManager);

        Event::dispatch(new UserNotificationCreated(1, 'notification-id', 'test.changed'));
    }

    public function test_badge_signal_does_not_serialize_public_queue_metadata(): void
    {
        $event = new BadgesChanged;
        $this->assertSame(['refresh' => true, 'event_id' => $event->eventId], $event->broadcastWith());
    }

    public function test_production_schedule_signal_contains_no_schedule_details(): void
    {
        $event = new ProductionScheduleChanged(
            scheduleHashId: 'schedule-hash',
            workOrderHashId: 'work-order-hash',
            action: 'status_changed',
            version: '2026-10-30T00:00:00.000000Z',
            actorHashId: 'actor-hash',
            eventId: 'event-123',
            timestamp: '2026-10-30T00:00:01.000000Z',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'event-123',
            'entity_type' => 'production_schedule',
            'entity_id' => 'schedule-hash',
            'action' => 'status_changed',
            'version' => '2026-10-30T00:00:00.000000Z',
            'actor_id' => 'actor-hash',
            'related' => [],
            'timestamp' => '2026-10-30T00:00:01.000000Z',
            'hash_id' => 'schedule-hash',
        ], $event->broadcastWith());
        $this->assertSame([
            'private-production_schedule.schedule-hash',
            'private-chain.work_order.work-order-hash',
            'private-entity.production_schedule.visible',
            'private-production.dashboard',
        ], array_map(static fn ($channel): string => $channel->name, $event->broadcastOn()));
    }

    public function test_entity_refresh_signals_include_the_shared_metadata_fields(): void
    {
        $events = [
            new MachineChanged('machine-hash', 'updated', 'v1', 'actor-hash', 'machine-event', '2026-10-30T00:00:01Z'),
            new MoldChanged('mold-hash', 'updated', 'v1', 'actor-hash', 'mold-event', '2026-10-30T00:00:01Z'),
            new MrpPlanChanged('plan-hash', 'sales-order-hash', 'updated', 'v1', 'actor-hash', 'plan-event', '2026-10-30T00:00:01Z'),
            new MrpRunChanged('run-hash', 'updated', 'v1', 'actor-hash', 'run-event', '2026-10-30T00:00:01Z'),
            new ProductionScheduleChanged('schedule-hash', 'work-order-hash', 'updated', 'v1', 'actor-hash', 'schedule-event', '2026-10-30T00:00:01Z'),
            new WorkOrderAssignmentChanged('work-order-hash', 'updated', 'v1', 'actor-hash', 'assignment-event', '2026-10-30T00:00:01Z'),
            new WorkOrderSchedulingDiagnosticChanged('work-order-hash', 'updated', 'v1', 'actor-hash', 'diagnostic-event', '2026-10-30T00:00:01Z'),
            new ProductionRoutingChanged('routing-hash', 'product-hash', 'updated', 'v1', 'actor-hash', 'routing-event', '2026-10-30T00:00:01Z'),
            new MaintenanceWorkOrderChanged('maintenance-hash', 'updated', 'v1', 'actor-hash', [], 'maintenance-event', '2026-10-30T00:00:01Z'),
        ];

        foreach ($events as $event) {
            $payload = $event->broadcastWith();

            foreach (['entity_type', 'entity_id', 'action', 'version', 'actor_id', 'related', 'timestamp'] as $field) {
                $this->assertArrayHasKey($field, $payload, $event::class.' is missing '.$field);
            }

            $this->assertSame($payload['hash_id'], $payload['entity_id'], $event::class.' must preserve its hash_id alias');
            $this->assertIsArray($payload['related']);
        }
    }

    public function test_contact_inquiry_signal_includes_only_its_public_record_reference(): void
    {
        $event = new ContactInquiryChanged(
            action: 'status_changed',
            version: '2026-10-30T00:00:00.000000Z',
            actorHashId: 'actor-hash',
            eventId: 'event-123',
            timestamp: '2026-10-30T00:00:01.000000Z',
            entityHashId: 'inquiry-hash',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'event-123',
            'entity_type' => 'contact_inquiry',
            'entity_id' => 'inquiry-hash',
            'action' => 'status_changed',
            'version' => '2026-10-30T00:00:00.000000Z',
            'actor_id' => 'actor-hash',
            'timestamp' => '2026-10-30T00:00:01.000000Z',
        ], $event->broadcastWith());
    }

    public function test_contact_inquiry_signal_decodes_legacy_outbox_payloads_without_record_reference(): void
    {
        $event = app(OutboxEventCodec::class)->decode(ContactInquiryChanged::class, [
            'action' => 'status_changed',
            'version' => '2026-10-30T00:00:00.000000Z',
            'actorHashId' => null,
            'eventId' => 'legacy-event-123',
            'timestamp' => '2026-10-30T00:00:01.000000Z',
        ]);

        $this->assertInstanceOf(ContactInquiryChanged::class, $event);
        $this->assertNull($event->broadcastWith()['entity_id']);
    }

    public function test_mrp_plan_signal_contains_only_refresh_metadata_and_related_hash_ids(): void
    {
        $event = new MrpPlanChanged(
            planHashId: 'plan-hash',
            salesOrderHashId: 'so-hash',
            action: 'status_changed',
            version: '2026-10-30T04:00:01.000000Z',
            actorHashId: 'actor-hash',
            eventId: 'plan-event-id',
            timestamp: '2026-10-30T04:00:02.000000Z',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'plan-event-id',
            'entity_type' => 'mrp_plan',
            'hash_id' => 'plan-hash',
            'action' => 'status_changed',
            'version' => '2026-10-30T04:00:01.000000Z',
            'actor_id' => 'actor-hash',
            'related' => [['entity_type' => 'sales_order', 'hash_id' => 'so-hash']],
            'timestamp' => '2026-10-30T04:00:02.000000Z',
        ], $event->broadcastWith());
        $this->assertSame(['private-mrp.plan.plan-hash', 'private-entity.mrp_plan.visible'], array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $event->broadcastOn(),
        ));
        $this->assertArrayNotHasKey('diagnostics', $event->broadcastWith());
        $this->assertArrayNotHasKey('cost_summary', $event->broadcastWith());
    }

    public function test_mrp_run_signal_omits_run_summary_and_uses_private_list_channel(): void
    {
        $event = new MrpRunChanged(
            runHashId: 'run-hash',
            action: 'status_changed',
            version: '2026-10-30T04:00:01.000000Z',
            actorHashId: 'actor-hash',
            eventId: 'run-event-id',
            timestamp: '2026-10-30T04:00:02.000000Z',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'run-event-id',
            'entity_type' => 'mrp_run',
            'hash_id' => 'run-hash',
            'action' => 'status_changed',
            'version' => '2026-10-30T04:00:01.000000Z',
            'actor_id' => 'actor-hash',
            'timestamp' => '2026-10-30T04:00:02.000000Z',
        ], $event->broadcastWith());
        $this->assertSame('private-entity.mrp_run.visible', $event->broadcastOn()[0]->name);
        $this->assertArrayNotHasKey('summary', $event->broadcastWith());
        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
    }

    public function test_mold_signal_contains_only_refresh_metadata_and_private_channels(): void
    {
        $event = new MoldChanged(
            moldHashId: 'mold-hash',
            action: 'status_changed',
            version: '2026-10-30T00:00:00.000000Z',
            actorHashId: 'actor-hash',
            eventId: 'event-123',
            timestamp: '2026-10-30T00:00:01.000000Z',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'event-123',
            'entity_type' => 'mold',
            'hash_id' => 'mold-hash',
            'action' => 'status_changed',
            'version' => '2026-10-30T00:00:00.000000Z',
            'actor_id' => 'actor-hash',
            'timestamp' => '2026-10-30T00:00:01.000000Z',
        ], $event->broadcastWith());
        $this->assertSame([
            'private-production.mold.mold-hash',
            'private-entity.mold.visible',
            'private-production.dashboard',
        ], array_map(static fn ($channel): string => $channel->name, $event->broadcastOn()));
    }

    public function test_bom_signal_contains_only_refresh_metadata_and_private_channels(): void
    {
        $event = new BomChanged(
            bomHashId: 'bom-hash',
            productHashId: 'product-hash',
            action: 'updated',
            version: '2026-10-30T00:00:00.000000Z',
            actorHashId: 'actor-hash',
            eventId: 'event-123',
            timestamp: '2026-10-30T00:00:01.000000Z',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'event-123',
            'entity_type' => 'bom',
            'hash_id' => 'bom-hash',
            'action' => 'updated',
            'version' => '2026-10-30T00:00:00.000000Z',
            'actor_id' => 'actor-hash',
            'related' => [['entity_type' => 'product', 'hash_id' => 'product-hash']],
            'timestamp' => '2026-10-30T00:00:01.000000Z',
        ], $event->broadcastWith());
        $this->assertSame([
            'private-production.bom.bom-hash',
            'private-entity.bom.visible',
            'private-mrp.product-bom.product-hash',
        ], array_map(static fn ($channel): string => $channel->name, $event->broadcastOn()));
    }

    public function test_rfq_signal_uses_a_content_free_actor_scoped_envelope(): void
    {
        $event = new RequestForQuoteChanged(
            rfqHashId: 'rfq-hash',
            action: 'status_changed',
            version: '2026-10-30T00:00:00Z',
            actorId: 42,
            eventId: 'event-123',
            actorHashId: 'actor-hash',
            timestamp: '2026-10-30T00:00:01+00:00',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'event-123',
            'entity_type' => 'request_for_quote',
            'entity_id' => 'rfq-hash',
            'action' => 'status_changed',
            'version' => '2026-10-30T00:00:00Z',
            'actor_id' => 'actor-hash',
            'related' => [],
            'timestamp' => '2026-10-30T00:00:01+00:00',
        ], $event->broadcastWith());
        $this->assertArrayNotHasKey('actorId', $event->broadcastWith());
        $this->assertSame([
            'rfq-hash',
            'event-123',
        ], [
            $event->rfqHashId,
            $event->eventId,
        ]);
        $legacy = app(OutboxEventCodec::class)->decode(RequestForQuoteChanged::class, [
            'rfqHashId' => 'legacy-rfq-hash',
            'action' => 'updated',
            'version' => 'legacy-version',
            'actorId' => 42,
            'eventId' => 'legacy-rfq-event',
        ]);
        $this->assertSame('legacy-rfq-event', $legacy->eventId);
        $this->assertNull($legacy->actorHashId);
    }

    public function test_supplier_catalog_and_vendor_rfq_pings_use_safe_refresh_envelopes(): void
    {
        $events = [
            [
                'event' => new SupplierCatalogChanged(
                    action: 'approved',
                    version: '2026-11-04T22:00:00+00:00',
                    vendorHashId: 'vendor-hash',
                    eventId: 'supplier-catalog-event',
                    timestamp: '2026-11-04T22:00:01+00:00',
                ),
                'refresh' => true,
                'event_id' => 'supplier-catalog-event',
                'entity_type' => 'supplier_catalog',
                'entity_id' => null,
                'action' => 'approved',
                'version' => '2026-11-04T22:00:00+00:00',
                'actor_id' => null,
                'related' => [],
                'timestamp' => '2026-11-04T22:00:01+00:00',
                'channels' => [
                    'private-entity.supplier_listing.visible',
                    'private-entity.approved_supplier.visible',
                    'private-supplier.vendor.vendor-hash',
                ],
            ],
            [
                'event' => new SupplierRfqChanged(
                    rfqHashId: 'rfq-hash',
                    action: 'published',
                    version: '2026-11-04T22:00:02+00:00',
                    supplierVendorHashIds: ['vendor-hash'],
                    eventId: 'supplier-rfq-event',
                    timestamp: '2026-11-04T22:00:03+00:00',
                ),
                'refresh' => true,
                'event_id' => 'supplier-rfq-event',
                'entity_type' => 'request_for_quote',
                'entity_id' => 'rfq-hash',
                'action' => 'published',
                'version' => '2026-11-04T22:00:02+00:00',
                'actor_id' => null,
                'related' => [],
                'timestamp' => '2026-11-04T22:00:03+00:00',
                'hash_id' => 'rfq-hash',
                'channels' => ['private-supplier.vendor.vendor-hash'],
            ],
            [
                'event' => new SupplierPerformanceChanged(
                    entityId: 'supplier-hash',
                    action: 'updated',
                    version: '2026-11-04T22:00:04+00:00',
                    actorId: 'actor-hash',
                    eventId: 'supplier-performance-event',
                    timestamp: '2026-11-04T22:00:05+00:00',
                ),
                'refresh' => true,
                'event_id' => 'supplier-performance-event',
                'entity_type' => 'supplier_performance',
                'entity_id' => 'supplier-hash',
                'action' => 'updated',
                'version' => '2026-11-04T22:00:04+00:00',
                'actor_id' => 'actor-hash',
                'related' => [],
                'timestamp' => '2026-11-04T22:00:05+00:00',
                'channels' => ['private-entity.supplier_performance.visible'],
            ],
        ];
        $codec = app(OutboxEventCodec::class);

        foreach ($events as $spec) {
            $event = $spec['event'];
            $channels = $spec['channels'];
            unset($spec['event'], $spec['channels']);

            $this->assertSame($channels, array_map(
                static fn (PrivateChannel $channel): string => $channel->name,
                $event->broadcastOn(),
            ));
            $this->assertSame($spec, $event->broadcastWith());
            $encoded = $codec->encode($event);
            $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
            $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());

            if ($event instanceof SupplierCatalogChanged) {
                $legacy = $codec->decode(SupplierCatalogChanged::class, [
                    'action' => 'approved',
                    'version' => 'legacy-version',
                    'vendorHashId' => 'vendor-hash',
                    'eventId' => 'legacy-supplier-catalog-event',
                ]);
                $this->assertSame('legacy-supplier-catalog-event', $legacy->eventId);
                $this->assertSame('vendor-hash', $legacy->vendorHashId);
            }
        }
    }

    public function test_inspection_signal_only_exposes_refresh_metadata_on_the_wire(): void
    {
        $event = new InspectionChanged(
            inspectionHashId: 'inspection-hash',
            action: 'updated',
            version: '2026-10-30T00:00:00Z',
            eventId: 'event-123',
            actorHashId: 'reviewer-hash',
            timestamp: '2026-10-30T00:00:01Z',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'event-123',
            'entity_type' => 'inspection',
            'entity_id' => 'inspection-hash',
            'action' => 'updated',
            'version' => '2026-10-30T00:00:00Z',
            'actor_id' => 'reviewer-hash',
            'related' => [],
            'timestamp' => '2026-10-30T00:00:01Z',
        ], $event->broadcastWith());
        $this->assertArrayNotHasKey('inspectionHashId', $event->broadcastWith());
        $this->assertArrayNotHasKey('actorId', $event->broadcastWith());
        $this->assertSame([
            'private-inspection.inspection-hash',
            'private-entity.inspection.visible',
        ], array_map(static fn ($channel): string => $channel->name, $event->broadcastOn()));
    }

    public function test_inspection_signal_keeps_legacy_outbox_actor_id_private(): void
    {
        $event = app(OutboxEventCodec::class)->decode(InspectionChanged::class, [
            'inspectionHashId' => 'legacy-inspection-hash',
            'action' => 'updated',
            'version' => '2026-10-30T00:00:00Z',
            'actorId' => 42,
            'eventId' => 'legacy-event-123',
        ]);

        $this->assertInstanceOf(InspectionChanged::class, $event);
        $this->assertSame('legacy-inspection-hash', $event->broadcastWith()['entity_id']);
        $this->assertNull($event->broadcastWith()['actor_id']);
    }

    public function test_quality_record_signal_uses_private_channels_and_minimal_wire_payload(): void
    {
        $event = new QualityRecordChanged(
            entityType: 'ppap',
            hashId: 'ppap-hash',
            action: 'status_changed',
            version: '2026-10-30T00:00:00Z',
            actorId: 42,
            eventId: 'event-456',
            actorHashId: 'quality-user-hash',
            timestamp: '2026-10-30T00:00:01Z',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'event-456',
            'entity_type' => 'ppap',
            'entity_id' => 'ppap-hash',
            'action' => 'status_changed',
            'version' => '2026-10-30T00:00:00Z',
            'actor_id' => 'quality-user-hash',
            'related' => [],
            'timestamp' => '2026-10-30T00:00:01Z',
        ], $event->broadcastWith());
        $this->assertArrayNotHasKey('hashId', $event->broadcastWith());
        $this->assertArrayNotHasKey('actorId', $event->broadcastWith());
        $this->assertSame([
            'private-ppap.ppap-hash',
            'private-entity.ppap.visible',
        ], array_map(static fn ($channel): string => $channel->name, $event->broadcastOn()));

        $legacy = app(OutboxEventCodec::class)->decode(QualityRecordChanged::class, [
            'entityType' => 'ppap',
            'hashId' => 'legacy-ppap-hash',
            'action' => 'updated',
            'version' => 'legacy-version',
            'actorId' => 42,
            'eventId' => 'legacy-quality-event',
        ]);
        $this->assertSame('legacy-quality-event', $legacy->eventId);
        $this->assertNull($legacy->actorHashId);
    }

    public function test_ncr_signal_only_exposes_refresh_metadata_on_the_wire(): void
    {
        $event = new NcrChanged(
            ncrHashId: 'ncr-hash',
            action: 'updated',
            version: '2026-10-30T00:00:00Z',
            actorId: 42,
            eventId: 'event-456',
            actorHashId: 'quality-user-hash',
            timestamp: '2026-10-30T00:00:01Z',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'event-456',
            'entity_type' => 'ncr',
            'entity_id' => 'ncr-hash',
            'action' => 'updated',
            'version' => '2026-10-30T00:00:00Z',
            'actor_id' => 'quality-user-hash',
            'related' => [],
            'timestamp' => '2026-10-30T00:00:01Z',
        ], $event->broadcastWith());
        $this->assertArrayNotHasKey('ncrHashId', $event->broadcastWith());
        $this->assertArrayNotHasKey('actorId', $event->broadcastWith());
        $this->assertSame([
            'private-ncr.ncr-hash',
            'private-entity.ncr.visible',
        ], array_map(static fn ($channel): string => $channel->name, $event->broadcastOn()));

        $legacy = app(OutboxEventCodec::class)->decode(NcrChanged::class, [
            'ncrHashId' => 'legacy-ncr-hash',
            'action' => 'updated',
            'version' => 'legacy-version',
            'actorId' => 42,
            'eventId' => 'legacy-ncr-event',
        ]);
        $this->assertSame('legacy-ncr-event', $legacy->eventId);
        $this->assertNull($legacy->actorHashId);
    }

    public function test_shipment_lot_signal_uses_private_channels_and_minimal_wire_payload(): void
    {
        $event = new ShipmentLotChanged('lot-hash', 'created', '2026-10-30T00:00:00Z', 42, 'lot-event-1');

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'lot-event-1',
            'version' => '2026-10-30T00:00:00Z',
            'action' => 'created',
        ], $event->broadcastWith());
        $this->assertArrayNotHasKey('shipmentLotHashId', $event->broadcastWith());
        $this->assertArrayNotHasKey('actorId', $event->broadcastWith());
        $this->assertSame([
            'private-shipment_lot.lot-hash',
            'private-entity.shipment_lot.visible',
        ], array_map(static fn ($channel): string => $channel->name, $event->broadcastOn()));
    }

    public function test_payroll_period_signal_exposes_only_refresh_metadata_and_private_channels(): void
    {
        $event = new PayrollPeriodChanged('period-hash', 'updated', '2026-10-30T00:00:00.000000+00:00', 'actor-hash', 'event-789', '2026-10-30T00:00:01Z');

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'event-789',
            'entity_type' => 'payroll_period',
            'hash_id' => 'period-hash',
            'action' => 'updated',
            'version' => '2026-10-30T00:00:00.000000+00:00',
            'actor_id' => 'actor-hash',
            'timestamp' => '2026-10-30T00:00:01Z',
        ], $event->broadcastWith());
        $this->assertSame([
            'private-payroll.period.period-hash',
            'private-entity.payroll_period.visible',
        ], array_map(static fn ($channel): string => $channel->name, $event->broadcastOn()));
        $this->assertArrayNotHasKey('net_pay', $event->broadcastWith());
        $this->assertArrayNotHasKey('employee_id', $event->broadcastWith());
    }

    public function test_payroll_adjustment_signal_exposes_only_refresh_metadata_and_private_channels(): void
    {
        $event = new PayrollAdjustmentChanged(
            'adjustment-hash',
            'updated',
            '2026-10-30T00:00:00.000000+00:00',
            'actor-hash',
            'period-hash',
            'event-adjustment-1',
            '2026-10-30T00:00:01Z',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'event-adjustment-1',
            'entity_type' => 'payroll_adjustment',
            'hash_id' => 'adjustment-hash',
            'action' => 'updated',
            'version' => '2026-10-30T00:00:00.000000+00:00',
            'actor_id' => 'actor-hash',
            'related' => [['entity_type' => 'payroll_period', 'hash_id' => 'period-hash']],
            'timestamp' => '2026-10-30T00:00:01Z',
        ], $event->broadcastWith());
        $this->assertSame([
            'private-payroll.adjustment.adjustment-hash',
            'private-entity.payroll_adjustment.visible',
        ], array_map(static fn ($channel): string => $channel->name, $event->broadcastOn()));
        $this->assertArrayNotHasKey('amount', $event->broadcastWith());
        $this->assertArrayNotHasKey('reason', $event->broadcastWith());

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(PayrollAdjustmentChanged::class, $decoded);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
    }

    public function test_work_order_assignment_signal_round_trips_through_the_durable_codec(): void
    {
        $event = new WorkOrderAssignmentChanged(
            workOrderHashId: 'work-order-hash',
            action: 'updated',
            version: '2026-10-30T00:00:00.000000+00:00',
            actorHashId: 'actor-hash',
            eventId: 'event-id',
            timestamp: '2026-10-30T00:00:00+00:00',
        );

        $encoded = app(OutboxEventCodec::class)->encode($event);
        $decoded = app(OutboxEventCodec::class)->decode($encoded['event_type'], $encoded['payload']);

        $this->assertInstanceOf(WorkOrderAssignmentChanged::class, $decoded);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
        $this->assertSame([
            'private-chain.work_order.work-order-hash',
            'private-entity.work_order.visible',
        ], array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn()));
    }

    public function test_attendance_changes_notify_authorized_work_order_list_viewers_only_when_a_crew_is_affected(): void
    {
        $unrelated = new AttendanceChanged('attendance-hash', 'updated', 'version-1');
        $unrelatedChannels = array_map(static fn (PrivateChannel $channel): string => $channel->name, $unrelated->broadcastOn());

        $this->assertNotContains('private-entity.work_order.visible', $unrelatedChannels);

        $related = new AttendanceChanged(
            attendanceHashId: 'attendance-hash',
            action: 'updated',
            version: 'version-2',
            workOrderHashIds: ['work-order-hash'],
        );

        $this->assertContains('private-chain.work_order.work-order-hash', array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $related->broadcastOn(),
        ));
        $this->assertContains('private-entity.work_order.visible', array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $related->broadcastOn(),
        ));
        $this->assertSame(['refresh', 'event_id', 'entity_type', 'action', 'version', 'actor_id', 'timestamp'], array_keys($related->broadcastWith()));
        $this->assertArrayNotHasKey('workOrderHashIds', $related->broadcastWith());
    }

    public function test_work_order_operation_signal_round_trips_and_exposes_no_record_data(): void
    {
        $event = new WorkOrderOperationChanged(
            workOrderHashId: 'work-order-hash',
            operationHashId: 'operation-hash',
            action: 'updated',
            version: '2026-10-30T00:00:00.000000+00:00',
            actorHashId: 'actor-hash',
            eventId: 'operation-event-id',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'operation-event-id',
        ], $event->broadcastWith());
        $this->assertSame([
            'private-chain.work_order.work-order-hash',
            'private-entity.work_order.visible',
            'private-production.dashboard',
        ], array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn()));

        $encoded = app(OutboxEventCodec::class)->encode($event);
        $decoded = app(OutboxEventCodec::class)->decode($encoded['event_type'], $encoded['payload']);

        $this->assertInstanceOf(WorkOrderOperationChanged::class, $decoded);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
        $this->assertArrayNotHasKey('operationHashId', $event->broadcastWith());
        $this->assertArrayNotHasKey('actorHashId', $event->broadcastWith());
    }

    public function test_grn_relation_refresh_uses_private_chain_and_list_channels(): void
    {
        $event = new GoodsReceiptNoteChanged(
            'grn-hash',
            'updated',
            '2026-10-30T00:00:00Z',
            42,
            'grn-event-1',
            'actor-hash',
            '2026-10-30T00:00:01+00:00',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'grn-event-1',
            'entity_type' => 'grn',
            'entity_id' => 'grn-hash',
            'action' => 'updated',
            'version' => '2026-10-30T00:00:00Z',
            'actor_id' => 'actor-hash',
            'related' => [],
            'timestamp' => '2026-10-30T00:00:01+00:00',
        ], $event->broadcastWith());
        $this->assertSame([
            'private-chain.grn.grn-hash',
            'private-entity.grn.visible',
            'private-dashboard.finance',
        ], array_map(static fn ($channel): string => $channel->name, $event->broadcastOn()));
    }

    public function test_grn_refresh_event_round_trips_through_the_durable_outbox_codec(): void
    {
        $codec = app(OutboxEventCodec::class);
        $event = new GoodsReceiptNoteChanged('grn-hash', 'updated', '2026-10-30T00:00:00Z', 42, 'grn-event-1');
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);

        $this->assertInstanceOf(GoodsReceiptNoteChanged::class, $decoded);
        $this->assertSame('grn-hash', $decoded->grnHashId);
        $this->assertSame('grn-event-1', $decoded->eventId);
    }

    public function test_return_request_signal_scopes_lists_and_keeps_business_data_off_the_wire(): void
    {
        $event = new ReturnRequestChanged(
            'rma-hash',
            'updated',
            '2026-10-30T00:00:00Z',
            ['customer', 'all'],
            42,
            'rma-event-1',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'rma-event-1',
            'version' => '2026-10-30T00:00:00Z',
            'action' => 'updated',
        ], $event->broadcastWith());
        $this->assertSame([
            'private-return_request.rma-hash',
            'private-entity.return_request.customer',
            'private-entity.return_request.all',
        ], array_map(static fn ($channel): string => $channel->name, $event->broadcastOn()));

        $encoded = app(OutboxEventCodec::class)->encode($event);
        $decoded = app(OutboxEventCodec::class)->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(ReturnRequestChanged::class, $decoded);
        $this->assertSame(['customer', 'all'], $decoded->listScopes);
    }

    public function test_customer_return_refresh_is_private_to_its_customer_account(): void
    {
        $event = new ReturnRequestChanged(
            hashId: 'rma-hash',
            action: 'updated',
            version: '2026-10-30T00:00:00Z',
            listScopes: ['customer', 'all'],
            customerHashId: 'customer-hash',
        );

        $this->assertSame([
            'private-return_request.rma-hash',
            'private-entity.return_request.customer',
            'private-entity.return_request.all',
            'private-customer.account.customer-hash',
        ], array_map(static fn ($channel): string => $channel->name, $event->broadcastOn()));
        $this->assertArrayNotHasKey('customerHashId', $event->broadcastWith());
    }

    public function test_return_case_signal_uses_private_scopes_and_a_content_free_wire_payload(): void
    {
        $event = new ReturnCaseChanged(
            'case-hash',
            'reply',
            '2026-10-30T00:00:00Z',
            ['customer', 'all'],
            'customer-hash',
            null,
            42,
            true,
            'case-event-1',
        );

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'case-event-1',
            'hash_id' => 'case-hash',
            'version' => '2026-10-30T00:00:00Z',
        ], $event->broadcastWith());
        $this->assertSame([
            'private-return_case.case-hash',
            'private-entity.return_case.customer',
            'private-entity.return_case.all',
            'private-customer.account.customer-hash',
        ], array_map(static fn ($channel): string => $channel->name, $event->broadcastOn()));

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(ReturnCaseChanged::class, $decoded);
        $this->assertSame('customer-hash', $decoded->customerHashId);
        $this->assertTrue($decoded->publicChange);
    }

    public function test_maintenance_work_order_signal_uses_private_scopes_and_whitelisted_payload(): void
    {
        $event = new MaintenanceWorkOrderChanged(
            hashId: 'mwo-hash',
            action: 'updated',
            version: '2026-10-30T04:00:00.123456+00:00',
            actorId: 'user-hash',
            viewerUserHashIds: ['assigned-user-hash'],
            eventId: 'maintenance-event-id',
            timestamp: '2026-10-30T04:00:01.000000Z',
        );

        $this->assertSame([
            'private-maintenance_work_order.mwo-hash',
            'private-entity.maintenance_work_order.visible',
            'private-maintenance.dashboard',
            'private-maintenance.user.assigned-user-hash',
        ], array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn()));
        $payload = $event->broadcastWith();
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'maintenance-event-id',
            'entity_type' => 'maintenance_work_order',
            'hash_id' => 'mwo-hash',
            'action' => 'updated',
            'version' => '2026-10-30T04:00:00.123456+00:00',
            'actor_id' => 'user-hash',
        ], array_intersect_key($payload, array_flip([
            'refresh', 'event_id', 'entity_type', 'hash_id', 'action', 'version', 'actor_id',
        ])));
        $this->assertSame('2026-10-30T04:00:01.000000Z', $payload['timestamp']);
        $this->assertEqualsCanonicalizing([
            'refresh', 'event_id', 'entity_type', 'hash_id', 'action', 'version', 'actor_id', 'timestamp',
        ], array_keys($payload));
        $this->assertSame('maintenance.work-order.changed', $event->broadcastAs());
    }

    public function test_maintenance_schedule_signal_uses_private_scopes_and_a_content_free_payload(): void
    {
        $event = new MaintenanceScheduleChanged(
            hashId: 'schedule-hash',
            action: 'updated',
            version: '2026-10-30T04:00:00.123456+00:00',
            actorId: 'user-hash',
            eventId: 'schedule-event-id',
        );

        $this->assertSame([
            'private-maintenance_schedule.schedule-hash',
            'private-entity.maintenance_schedule.visible',
            'private-maintenance.dashboard',
        ], array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn()));
        $this->assertSame(['refresh' => true, 'event_id' => 'schedule-event-id'], $event->broadcastWith());
        $this->assertSame('maintenance.schedule.changed', $event->broadcastAs());
    }

    public function test_machine_change_signal_is_private_minimal_and_round_trips_through_outbox(): void
    {
        $event = new MachineChanged(
            machineHashId: 'machine-hash',
            action: 'status_changed',
            version: '2026-10-30T04:00:00.123456+00:00',
            actorHashId: 'actor-hash',
            eventId: 'machine-event-id',
            timestamp: '2026-10-30T04:00:01.000000Z',
        );

        $this->assertSame([
            'private-production.machine.machine-hash',
            'private-entity.machine.visible',
            'private-production.dashboard',
        ], array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn()));
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'machine-event-id',
            'entity_type' => 'machine',
            'hash_id' => 'machine-hash',
            'action' => 'status_changed',
            'version' => '2026-10-30T04:00:00.123456+00:00',
            'actor_id' => 'actor-hash',
            'timestamp' => '2026-10-30T04:00:01.000000Z',
        ], $event->broadcastWith());

        $encoded = app(OutboxEventCodec::class)->encode($event);
        $decoded = app(OutboxEventCodec::class)->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(MachineChanged::class, $decoded);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
        $this->assertSame('chain.refresh', $event->broadcastAs());
    }

    public function test_clearance_signal_uses_private_record_and_scoped_user_channels_without_clearance_data(): void
    {
        $event = new ClearanceChanged(
            hashId: 'clearance-hash',
            action: 'updated',
            version: '2026-10-30T14:00:00.000000+00:00',
            actorId: 'actor-hash',
            viewerUserHashIds: ['viewer-hash'],
            eventId: 'clearance-event',
            timestamp: '2026-10-30T14:00:00+00:00',
        );

        $this->assertSame([
            'private-clearance.clearance-hash',
            'private-hr.clearance.user.viewer-hash',
        ], array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn()));
        $this->assertSame('hr.clearance.changed', $event->broadcastAs());
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'clearance-event',
            'entity_type' => 'clearance',
            'hash_id' => 'clearance-hash',
            'action' => 'updated',
            'version' => '2026-10-30T14:00:00.000000+00:00',
            'actor_id' => 'actor-hash',
            'timestamp' => '2026-10-30T14:00:00+00:00',
        ], $event->broadcastWith());
        $this->assertArrayNotHasKey('clearance_items', $event->broadcastWith());
        $this->assertArrayNotHasKey('final_pay_amount', $event->broadcastWith());
    }

    public function test_production_routing_signal_round_trips_minimal_metadata_on_private_channels(): void
    {
        $event = new ProductionRoutingChanged(
            routingHashId: 'routing-hash',
            productHashId: 'product-hash',
            action: 'status_changed',
            version: '2026-10-30T14:00:00.000000+00:00',
            actorHashId: 'actor-hash',
            eventId: 'routing-event',
            timestamp: '2026-10-30T14:00:01+00:00',
        );

        $this->assertSame([
            'private-entity.production_routing.visible',
            'private-production.routing.routing-hash',
        ], array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn()));
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'routing-event',
            'entity_type' => 'product_routing',
            'hash_id' => 'routing-hash',
            'action' => 'status_changed',
            'version' => '2026-10-30T14:00:00.000000+00:00',
            'actor_id' => 'actor-hash',
            'timestamp' => '2026-10-30T14:00:01+00:00',
            'related' => [['entity_type' => 'product', 'hash_id' => 'product-hash']],
        ], $event->broadcastWith());
        $this->assertArrayNotHasKey('operations', $event->broadcastWith());
        $this->assertArrayNotHasKey('notes', $event->broadcastWith());

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(ProductionRoutingChanged::class, $decoded);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
    }

    public function test_inventory_stock_signal_uses_the_standard_envelope_and_keeps_occurred_at_alias(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-11-04T20:30:00+00:00');
        $event = new InventoryStockChanged('stock-event', 'stock-generation-v2', $occurredAt);

        $this->assertSame([
            'refresh' => true,
            'event_id' => 'stock-event',
            'entity_type' => 'inventory_stock',
            'entity_id' => null,
            'action' => 'updated',
            'version' => 'stock-generation-v2',
            'actor_id' => null,
            'related' => [],
            'timestamp' => '2026-11-04T20:30:00+00:00',
            'occurred_at' => '2026-11-04T20:30:00+00:00',
        ], $event->broadcastWith());
    }

    public function test_inventory_entity_signals_use_the_shared_envelope_and_keep_legacy_outbox_rows_decodable(): void
    {
        $eventSpecs = [
            [
                'event' => new GoodsReceiptNoteChanged(
                    grnHashId: 'grn-hash',
                    action: 'accepted',
                    version: '2026-11-04T19:59:00.123456+00:00',
                    actorId: 17,
                    eventId: 'grn-event',
                    actorHashId: 'actor-hash',
                    timestamp: '2026-11-04T19:59:01+00:00',
                ),
                'entity_type' => 'grn',
                'entity_id' => 'grn-hash',
                'event_id' => 'grn-event',
                'channels' => [
                    'private-chain.grn.grn-hash',
                    'private-entity.grn.visible',
                    'private-dashboard.finance',
                ],
            ],
            [
                'event' => new ItemChanged(
                    itemHashId: 'item-hash',
                    action: 'updated',
                    version: '2026-11-04T19:59:02.123456+00:00',
                    actorId: 17,
                    eventId: 'item-event',
                    actorHashId: 'actor-hash',
                    timestamp: '2026-11-04T19:59:03+00:00',
                ),
                'entity_type' => 'item',
                'entity_id' => 'item-hash',
                'event_id' => 'item-event',
                'channels' => [
                    'private-inventory.item.item-hash',
                    'private-entity.item.visible',
                ],
            ],
            [
                'event' => new TransferOrderChanged(
                    hashId: 'transfer-hash',
                    action: 'status_changed',
                    version: '2026-11-04T19:59:04.123456+00:00',
                    eventId: 'transfer-event',
                    actorHashId: 'actor-hash',
                    timestamp: '2026-11-04T19:59:05+00:00',
                ),
                'entity_type' => 'transfer_order',
                'entity_id' => 'transfer-hash',
                'event_id' => 'transfer-event',
                'channels' => [
                    'private-inventory.transfer-order.transfer-hash',
                    'private-entity.transfer_order.visible',
                ],
            ],
            [
                'event' => new WarehouseLayoutChanged(
                    eventId: 'warehouse-layout-event',
                    action: 'updated',
                    version: '2026-11-04T19:59:06.123456+00:00',
                    timestamp: '2026-11-04T19:59:07+00:00',
                    actorHashId: 'actor-hash',
                ),
                'entity_type' => 'warehouse_layout',
                'entity_id' => null,
                'event_id' => 'warehouse-layout-event',
                'channels' => ['private-inventory.warehouse-layout'],
            ],
            [
                'event' => new StockAdjustmentChanged(
                    entityHashId: 'adjustment-hash',
                    action: 'approved',
                    version: '2026-11-04T20:00:00.123456+00:00',
                    actorHashId: 'actor-hash',
                    eventId: 'adjustment-event',
                    timestamp: '2026-11-04T20:00:01+00:00',
                ),
                'entity_type' => 'stock_adjustment',
                'entity_id' => 'adjustment-hash',
                'event_id' => 'adjustment-event',
                'channels' => ['private-entity.stock_adjustment.visible'],
            ],
            [
                'event' => new ItemCostReviewChanged(
                    entityHashId: 'cost-review-hash',
                    action: 'updated',
                    version: '2026-11-04T20:00:02.123456+00:00',
                    actorHashId: 'actor-hash',
                    eventId: 'cost-review-event',
                    timestamp: '2026-11-04T20:00:03+00:00',
                ),
                'entity_type' => 'item_cost_review',
                'entity_id' => 'cost-review-hash',
                'event_id' => 'cost-review-event',
                'channels' => ['private-entity.item_cost_review.visible'],
            ],
            [
                'event' => new MaterialIssueSlipChanged(
                    entityHashId: 'issue-slip-hash',
                    action: 'issued',
                    version: '2026-11-04T20:00:04.123456+00:00',
                    actorHashId: 'actor-hash',
                    eventId: 'issue-slip-event',
                    timestamp: '2026-11-04T20:00:05+00:00',
                ),
                'entity_type' => 'material_issue_slip',
                'entity_id' => 'issue-slip-hash',
                'event_id' => 'issue-slip-event',
                'channels' => [
                    'private-entity.material_issue_slip.visible',
                    'private-inventory.material-issue.issue-slip-hash',
                ],
            ],
            [
                'event' => new MaterialReviewRecordChanged(
                    entityHashId: 'mrb-hash',
                    action: 'updated',
                    version: '2026-11-04T20:00:06.123456+00:00',
                    actorHashId: 'actor-hash',
                    eventId: 'mrb-event',
                    timestamp: '2026-11-04T20:00:07+00:00',
                ),
                'entity_type' => 'material_review_record',
                'entity_id' => 'mrb-hash',
                'event_id' => 'mrb-event',
                'channels' => [
                    'private-entity.material_review_record.visible',
                    'private-inventory.mrb.mrb-hash',
                ],
            ],
        ];

        $codec = app(OutboxEventCodec::class);

        foreach ($eventSpecs as $spec) {
            $event = $spec['event'];
            $this->assertSame($spec['channels'], array_map(
                static fn (PrivateChannel $channel): string => $channel->name,
                $event->broadcastOn(),
            ));
            $expectedPayload = [
                'refresh' => true,
                'event_id' => $spec['event_id'],
                'entity_type' => $spec['entity_type'],
                'entity_id' => $spec['entity_id'],
                'action' => $event->action,
                'version' => $event->version,
                'actor_id' => 'actor-hash',
                'related' => [],
                'timestamp' => $event->timestamp,
            ];
            if ($event instanceof TransferOrderChanged) {
                $expectedPayload['hash_id'] = $spec['entity_id'];
            }
            $this->assertSame($expectedPayload, $event->broadcastWith());
            $this->assertArrayNotHasKey('quantity', $event->broadcastWith());
            $this->assertArrayNotHasKey('notes', $event->broadcastWith());

            $encoded = $codec->encode($event);
            $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
            $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());

            $legacy = $codec->decode($event::class, [
                ...$this->legacyEntityOutboxPayload($event, $spec),
            ]);
            $this->assertSame($spec['event_id'], $legacy->eventId);
        }
    }

    /** @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function legacyEntityOutboxPayload(object $event, array $spec): array
    {
        $payload = match (true) {
            $event instanceof GoodsReceiptNoteChanged => ['grnHashId' => $spec['entity_id'], 'actorId' => 17],
            $event instanceof ItemChanged => ['itemHashId' => $spec['entity_id'], 'actorId' => 17],
            $event instanceof TransferOrderChanged => ['hashId' => $spec['entity_id']],
            $event instanceof WarehouseLayoutChanged => [
                'refreshProductionReadiness' => false,
                'timestamp' => '2026-11-04T19:59:07+00:00',
            ],
            default => ['entityHashId' => $spec['entity_id']],
        };

        return [
            ...$payload,
            'action' => $event->action,
            'version' => $event->version,
            'eventId' => $spec['event_id'],
            ...($event instanceof GoodsReceiptNoteChanged || $event instanceof ItemChanged || $event instanceof TransferOrderChanged || $event instanceof WarehouseLayoutChanged
                ? []
                : ['actorHashId' => 'actor-hash']),
        ];
    }

    public function test_stock_count_signal_uses_the_shared_minimal_event_envelope(): void
    {
        $event = new StockCountChanged(
            sessionHashId: 'stock-count-hash',
            action: 'status_changed',
            version: '2026-11-04T20:00:00.123456+00:00',
            eventId: 'stock-count-event',
            actorHashId: 'actor-hash',
            timestamp: '2026-11-04T20:00:01+00:00',
        );

        $this->assertSame([
            'private-inventory.stock-count-session.stock-count-hash',
            'private-entity.stock_count_session.visible',
        ], array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn()));
        $this->assertSame([
            'refresh' => true,
            'event_id' => 'stock-count-event',
            'entity_type' => 'stock_count_session',
            'entity_id' => 'stock-count-hash',
            'action' => 'status_changed',
            'version' => '2026-11-04T20:00:00.123456+00:00',
            'actor_id' => 'actor-hash',
            'related' => [],
            'timestamp' => '2026-11-04T20:00:01+00:00',
        ], $event->broadcastWith());
        $this->assertArrayNotHasKey('quantity', $event->broadcastWith());
        $this->assertArrayNotHasKey('notes', $event->broadcastWith());
        $this->assertArrayNotHasKey('variance', $event->broadcastWith());

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(StockCountChanged::class, $decoded);
        $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());

        $legacy = $codec->decode(StockCountChanged::class, [
            'sessionHashId' => 'legacy-stock-count-hash',
            'action' => 'updated',
            'version' => 'legacy-version',
            'eventId' => 'legacy-stock-count-event',
        ]);
        $this->assertInstanceOf(StockCountChanged::class, $legacy);
        $this->assertSame('legacy-stock-count-event', $legacy->eventId);
    }
}
