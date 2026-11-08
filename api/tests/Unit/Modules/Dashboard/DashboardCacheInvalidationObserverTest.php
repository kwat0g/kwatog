<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Dashboard;

use App\Common\Models\ApprovalDelegation;
use App\Common\Models\ApprovalRecord;
use App\Common\Models\ChainListenerRun;
use App\Common\Services\OutboxEventCodec;
use App\Common\Services\OutboxService;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\CRM\Models\Product;
use App\Modules\Dashboard\Events\ChainBottlenecksChanged;
use App\Modules\Dashboard\Events\DashboardWidgetDataChanged;
use App\Modules\Dashboard\Events\EmployeeDashboardChanged;
use App\Modules\Dashboard\Events\PpcDashboardChanged;
use App\Modules\Dashboard\Models\KpiDefinition;
use App\Modules\Dashboard\Models\KpiSnapshot;
use App\Modules\Dashboard\Observers\DashboardCacheInvalidationObserver;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;
use App\Modules\Dashboard\Services\PlantManagerDashboardRealtimeService;
use App\Modules\Dashboard\Services\PurchasingDashboardRealtimeService;
use App\Modules\Dashboard\Services\QualityDashboardRealtimeService;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderDefect;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class DashboardCacheInvalidationObserverTest extends TestCase
{
    public function test_widget_generation_rotation_stages_one_authorized_dashboard_refresh_signal(): void
    {
        $stagedEvent = null;
        $dedupeKey = null;
        $outbox = Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordCoalesced')->once()
            ->withArgs(function (object $event, string $key) use (&$stagedEvent, &$dedupeKey): bool {
                $stagedEvent = $event;
                $dedupeKey = $key;

                return true;
            });
        app()->instance(OutboxService::class, $outbox);

        DashboardCacheGeneration::touch('widget-summary:inventory.pending_issues');

        $this->assertInstanceOf(DashboardWidgetDataChanged::class, $stagedEvent);
        $this->assertStringStartsWith('dashboard-widget-data-refresh:', $dedupeKey);
        $this->assertSame('dashboard.widgets.changed', $stagedEvent->broadcastAs());
        $this->assertSame('private-dashboard.widgets', $stagedEvent->broadcastOn()[0]->name);
        $this->assertSame('dashboard_widget_data', $stagedEvent->broadcastWith()['entity_type']);
        $this->assertNull($stagedEvent->broadcastWith()['entity_id']);
        $this->assertSame(
            DashboardWidgetDataChanged::class,
            app(OutboxEventCodec::class)->encode($stagedEvent)['event_type'],
        );
    }

    public function test_global_dashboard_generation_rotation_stages_widget_refresh_signal(): void
    {
        $stagedEvent = null;
        $outbox = Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordCoalesced')->once()
            ->withArgs(function (object $event) use (&$stagedEvent): bool {
                $stagedEvent = $event;

                return $event instanceof DashboardWidgetDataChanged;
            });
        app()->instance(OutboxService::class, $outbox);

        DashboardCacheGeneration::touch();

        $this->assertInstanceOf(DashboardWidgetDataChanged::class, $stagedEvent);
        $this->assertSame('dashboard.widgets.changed', $stagedEvent->broadcastAs());
        $this->assertSame('private-dashboard.widgets', $stagedEvent->broadcastOn()[0]->name);
    }

    public function test_stock_movement_write_stages_a_content_free_chain_bottlenecks_refresh(): void
    {
        $stagedEvent = null;
        $dedupeKey = null;
        $outbox = Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordCoalesced')->twice()
            ->withArgs(function (object $event, string $key) use (&$stagedEvent, &$dedupeKey): bool {
                if ($event instanceof ChainBottlenecksChanged) {
                    $stagedEvent = $event;
                    $dedupeKey = $key;
                }

                return true;
            });
        app()->instance(OutboxService::class, $outbox);

        (new DashboardCacheInvalidationObserver)->invalidateSource(new StockMovement);

        $this->assertInstanceOf(ChainBottlenecksChanged::class, $stagedEvent);
        $this->assertStringStartsWith('chain-bottlenecks-refresh:', $dedupeKey);
        $this->assertSame('chain.bottlenecks.changed', $stagedEvent->broadcastAs());
        $this->assertSame('private-dashboard.chain-bottlenecks', $stagedEvent->broadcastOn()[0]->name);
        $this->assertSame([
            'refresh' => true,
            'event_id' => $stagedEvent->eventId,
            'entity_type' => 'chain_bottlenecks',
            'timestamp' => $stagedEvent->timestamp,
        ], $stagedEvent->broadcastWith());
        $this->assertSame(
            ChainBottlenecksChanged::class,
            app(OutboxEventCodec::class)->encode($stagedEvent)['event_type'],
        );
    }

    public function test_approval_record_write_stages_a_chain_bottlenecks_refresh(): void
    {
        $stagedEvent = null;
        $outbox = Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordCoalesced')->once()
            ->withArgs(function (object $event, string $key) use (&$stagedEvent): bool {
                $stagedEvent = $event;

                return str_starts_with($key, 'chain-bottlenecks-refresh:');
            });
        app()->instance(OutboxService::class, $outbox);

        (new DashboardCacheInvalidationObserver)->invalidateSource(new ApprovalRecord);

        $this->assertInstanceOf(ChainBottlenecksChanged::class, $stagedEvent);
        $this->assertSame('private-dashboard.chain-bottlenecks', $stagedEvent->broadcastOn()[0]->name);
    }

    public function test_chain_listener_run_write_stages_a_chain_bottlenecks_health_refresh(): void
    {
        $stagedEvent = null;
        $outbox = Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordCoalesced')->once()
            ->withArgs(function (object $event, string $key) use (&$stagedEvent): bool {
                $stagedEvent = $event;

                return str_starts_with($key, 'chain-bottlenecks-refresh:');
            });
        app()->instance(OutboxService::class, $outbox);

        (new DashboardCacheInvalidationObserver)->invalidateSource(new ChainListenerRun);

        $this->assertInstanceOf(ChainBottlenecksChanged::class, $stagedEvent);
        $this->assertSame('private-dashboard.chain-bottlenecks', $stagedEvent->broadcastOn()[0]->name);
    }

    public function test_approval_delegation_write_retires_approval_widget_generations(): void
    {
        $summaryBefore = DashboardCacheGeneration::current('widget-summary:approvals.pending');
        $richBefore = DashboardCacheGeneration::current('widget-rich:approvals.pending');

        (new DashboardCacheInvalidationObserver)->invalidateSource(new ApprovalDelegation);

        $this->assertNotSame($summaryBefore, DashboardCacheGeneration::current('widget-summary:approvals.pending'));
        $this->assertNotSame($richBefore, DashboardCacheGeneration::current('widget-rich:approvals.pending'));
    }

    public function test_kpi_definition_write_retires_dynamic_scalar_and_rich_widget_generations(): void
    {
        $summaryBefore = DashboardCacheGeneration::current('widget-summary:kpi.oee');
        $richBefore = DashboardCacheGeneration::current('widget-rich:kpi.oee');

        (new DashboardCacheInvalidationObserver)->invalidateSource(new KpiDefinition(['code' => 'oee']));

        $this->assertNotSame($summaryBefore, DashboardCacheGeneration::current('widget-summary:kpi.oee'));
        $this->assertNotSame($richBefore, DashboardCacheGeneration::current('widget-rich:kpi.oee'));
    }

    public function test_invoice_write_retires_rich_ar_aging_widget_generation(): void
    {
        $outbox = Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordCoalesced')->twice();
        app()->instance(OutboxService::class, $outbox);

        $before = DashboardCacheGeneration::current('widget-rich:finance.ar_aging');

        (new DashboardCacheInvalidationObserver)->invalidateSource(new Invoice);

        $this->assertNotSame(
            $before,
            DashboardCacheGeneration::current('widget-rich:finance.ar_aging'),
        );
    }

    public function test_ppc_dashboard_source_write_stages_a_refresh_for_ppc_viewers(): void
    {
        $stagedEvent = null;
        $dedupeKey = null;
        $outbox = Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordCoalesced')->once()
            ->withArgs(function (object $event, string $key) use (&$stagedEvent, &$dedupeKey): bool {
                $stagedEvent = $event;
                $dedupeKey = $key;

                return true;
            });
        app()->instance(OutboxService::class, $outbox);

        (new DashboardCacheInvalidationObserver)->invalidateSource(new MaintenanceWorkOrder);

        $this->assertInstanceOf(PpcDashboardChanged::class, $stagedEvent);
        $this->assertStringStartsWith('ppc-dashboard-refresh:', $dedupeKey);
        $this->assertSame('ppc.dashboard.changed', $stagedEvent->broadcastAs());
        $this->assertSame('private-dashboard.ppc', $stagedEvent->broadcastOn()[0]->name);
        $this->assertSame([
            'refresh' => true,
            'event_id' => $stagedEvent->eventId,
            'entity_type' => 'ppc_dashboard',
            'timestamp' => $stagedEvent->timestamp,
        ], $stagedEvent->broadcastWith());
        $this->assertSame(
            PpcDashboardChanged::class,
            app(OutboxEventCodec::class)->encode($stagedEvent)['event_type'],
        );
    }

    public function test_finance_dashboard_source_write_stages_a_refresh_for_aggregate_readers(): void
    {
        $eventTypes = [];
        $outbox = Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordCoalesced')->twice()
            ->withArgs(function (object $event, string $key) use (&$eventTypes): bool {
                $eventTypes[] = $event::class;

                return true;
            });
        app()->instance(OutboxService::class, $outbox);

        (new DashboardCacheInvalidationObserver)->invalidateSource(new Bill);

        $this->assertContains('App\\Modules\\Dashboard\\Events\\FinanceDashboardChanged', $eventTypes);
    }

    public function test_payroll_period_write_stages_employee_dashboard_refresh(): void
    {
        $eventTypes = [];
        $outbox = Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordCoalesced')->times(3)
            ->withArgs(function (object $event, string $key) use (&$eventTypes): bool {
                $eventTypes[] = $event::class;

                return true;
            });
        app()->instance(OutboxService::class, $outbox);

        (new DashboardCacheInvalidationObserver)->invalidateSource(new PayrollPeriod);

        $this->assertContains(EmployeeDashboardChanged::class, $eventTypes, implode(', ', $eventTypes));
    }

    public function test_department_and_vendor_changes_stage_purchasing_aggregate_refreshes(): void
    {
        $eventTypes = [];
        $outbox = Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordCoalesced')->times(6)
            ->withArgs(function (object $event, string $key) use (&$eventTypes): bool {
                $eventTypes[] = $event::class;

                return true;
            });
        app()->instance(OutboxService::class, $outbox);

        $observer = new DashboardCacheInvalidationObserver;
        foreach ([new Department, new Vendor] as $source) {
            $observer->invalidateSource($source);
        }

        $purchasingSignals = array_filter(
            $eventTypes,
            static fn (string $eventType): bool => $eventType === 'App\\Modules\\Dashboard\\Events\\PurchasingDashboardChanged',
        );
        $this->assertCount(2, $purchasingSignals);
    }

    public function test_chain_listener_run_retires_the_recovery_backlog_widget_generation(): void
    {
        $before = DashboardCacheGeneration::current('widget-summary:chain.recovery_backlog');

        (new DashboardCacheInvalidationObserver)->invalidateSource(new ChainListenerRun);

        $this->assertNotSame(
            $before,
            DashboardCacheGeneration::current('widget-summary:chain.recovery_backlog'),
        );
    }

    public function test_forecast_source_writes_retire_their_scalar_widget_generations(): void
    {
        $observer = new DashboardCacheInvalidationObserver;
        $sources = [
            [new Account, 'widget-summary:forecast.revenue'],
            [new JournalEntry, 'widget-summary:forecast.revenue'],
            [new Invoice, 'widget-summary:forecast.cash_flow'],
            [new Bill, 'widget-summary:forecast.cash_flow'],
            [new Employee, 'widget-summary:forecast.headcount'],
            [new Inspection, 'widget-summary:forecast.defect_rate'],
            [new Attendance, 'widget-summary:forecast.attendance'],
            [new LeaveRequest, 'widget-summary:forecast.attendance'],
            [new Machine, 'widget-summary:forecast.capacity'],
            [new WorkOrder, 'widget-summary:forecast.capacity'],
            [new KpiSnapshot, 'widget-summary:forecast.capacity'],
        ];

        foreach ($sources as [$source, $scope]) {
            $before = DashboardCacheGeneration::current($scope);
            $observer->invalidateSource($source);

            $this->assertNotSame($before, DashboardCacheGeneration::current($scope), $scope);
        }
    }

    public function test_supplier_risk_sources_retire_the_purchasing_dashboard_generation(): void
    {
        $realtime = Mockery::mock(PurchasingDashboardRealtimeService::class);
        $realtime->shouldReceive('stage')->twice();
        app()->instance(PurchasingDashboardRealtimeService::class, $realtime);

        $observer = new DashboardCacheInvalidationObserver;
        foreach ([new Item, new Product, new DemandForecast] as $source) {
            $before = DashboardCacheGeneration::current('dashboard:purchasing');
            $observer->invalidateSource($source);

            $this->assertNotSame($before, DashboardCacheGeneration::current('dashboard:purchasing'));
        }
    }

    public function test_machine_write_retires_rich_oee_dashboard_generations(): void
    {
        $globalBefore = DashboardCacheGeneration::current();
        $plantManagerBefore = DashboardCacheGeneration::current('dashboard:plant_manager');
        $utilizationBefore = DashboardCacheGeneration::current('widget-rich:machine.utilization');
        $oeeBefore = DashboardCacheGeneration::current('widget-rich:oee.gauges');

        (new DashboardCacheInvalidationObserver)->updated(new Machine);

        $this->assertSame($globalBefore, DashboardCacheGeneration::current());
        $this->assertNotSame($plantManagerBefore, DashboardCacheGeneration::current('dashboard:plant_manager'));
        $this->assertNotSame($utilizationBefore, DashboardCacheGeneration::current('widget-rich:machine.utilization'));
        $this->assertNotSame($oeeBefore, DashboardCacheGeneration::current('widget-rich:oee.gauges'));
    }

    public function test_production_output_write_retires_kpi_widget_snapshots(): void
    {
        $globalBefore = DashboardCacheGeneration::current();
        $qualityBefore = DashboardCacheGeneration::current('dashboard:quality');
        $plantManagerBefore = DashboardCacheGeneration::current('dashboard:plant_manager');
        $summaryBefore = DashboardCacheGeneration::current('widget-summary:production.kpi');
        $richBefore = DashboardCacheGeneration::current('widget-rich:production.kpi');

        (new DashboardCacheInvalidationObserver)->created(new WorkOrderOutput);

        $this->assertSame($globalBefore, DashboardCacheGeneration::current());
        $this->assertNotSame($qualityBefore, DashboardCacheGeneration::current('dashboard:quality'));
        $this->assertNotSame($plantManagerBefore, DashboardCacheGeneration::current('dashboard:plant_manager'));
        $this->assertNotSame($summaryBefore, DashboardCacheGeneration::current('widget-summary:production.kpi'));
        $this->assertNotSame($richBefore, DashboardCacheGeneration::current('widget-rich:production.kpi'));
    }

    public function test_machine_downtime_write_retires_oee_and_plant_manager_snapshots(): void
    {
        $globalBefore = DashboardCacheGeneration::current();
        $plantManagerBefore = DashboardCacheGeneration::current('dashboard:plant_manager');
        $utilizationBefore = DashboardCacheGeneration::current('widget-rich:machine.utilization');
        $oeeBefore = DashboardCacheGeneration::current('widget-rich:oee.gauges');

        (new DashboardCacheInvalidationObserver)->updated(new MachineDowntime);

        $this->assertSame($globalBefore, DashboardCacheGeneration::current());
        $this->assertNotSame($plantManagerBefore, DashboardCacheGeneration::current('dashboard:plant_manager'));
        $this->assertNotSame($utilizationBefore, DashboardCacheGeneration::current('widget-rich:machine.utilization'));
        $this->assertNotSame($oeeBefore, DashboardCacheGeneration::current('widget-rich:oee.gauges'));
    }

    public function test_plant_manager_account_source_retires_its_generation_and_stages_a_refresh(): void
    {
        $realtime = Mockery::mock(PlantManagerDashboardRealtimeService::class);
        $realtime->shouldReceive('stage')->once();
        app()->instance(PlantManagerDashboardRealtimeService::class, $realtime);

        $globalBefore = DashboardCacheGeneration::current();
        $plantManagerBefore = DashboardCacheGeneration::current('dashboard:plant_manager');

        (new DashboardCacheInvalidationObserver)->invalidateSource(new Account);

        $this->assertSame($globalBefore, DashboardCacheGeneration::current());
        $this->assertNotSame($plantManagerBefore, DashboardCacheGeneration::current('dashboard:plant_manager'));
    }

    public function test_shared_quality_and_plant_manager_source_retires_both_generations_and_stages_both_refreshes(): void
    {
        $quality = Mockery::mock(QualityDashboardRealtimeService::class);
        $quality->shouldReceive('stage')->once();
        app()->instance(QualityDashboardRealtimeService::class, $quality);

        $plantManager = Mockery::mock(PlantManagerDashboardRealtimeService::class);
        $plantManager->shouldReceive('stage')->once();
        app()->instance(PlantManagerDashboardRealtimeService::class, $plantManager);

        $qualityBefore = DashboardCacheGeneration::current('dashboard:quality');
        $plantManagerBefore = DashboardCacheGeneration::current('dashboard:plant_manager');

        (new DashboardCacheInvalidationObserver)->invalidateSource(new WorkOrderDefect);

        $this->assertNotSame($qualityBefore, DashboardCacheGeneration::current('dashboard:quality'));
        $this->assertNotSame($plantManagerBefore, DashboardCacheGeneration::current('dashboard:plant_manager'));
    }

    public function test_maintenance_schedule_write_rotates_only_its_dashboard_cache_families(): void
    {
        Cache::put('dashboard:cache:version', 'global-before', 60);
        Cache::put('dashboard:cache:scope:'.hash('sha256', 'dashboard:ppc'), 'ppc-before', 60);
        Cache::put('dashboard:cache:scope:'.hash('sha256', 'widget-summary:maintenance.due_schedules'), 'widget-before', 60);
        $globalBefore = DashboardCacheGeneration::current();
        $ppcBefore = DashboardCacheGeneration::current('dashboard:ppc');
        $widgetBefore = DashboardCacheGeneration::current('widget-summary:maintenance.due_schedules');
        $observer = new DashboardCacheInvalidationObserver;

        $observer->updated(new MaintenanceSchedule);

        $this->assertSame($globalBefore, DashboardCacheGeneration::current());
        $this->assertNotSame($ppcBefore, DashboardCacheGeneration::current('dashboard:ppc'));
        $this->assertNotSame($widgetBefore, DashboardCacheGeneration::current('widget-summary:maintenance.due_schedules'));
    }
}
