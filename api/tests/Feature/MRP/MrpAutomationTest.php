<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Services\AlertEngineService;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\MRP\Events\MrpReplanRequested;
use App\Modules\MRP\Jobs\RunAutomaticMrpJob;
use App\Modules\MRP\Services\MrpAutomationService;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\MRP\Services\MrpScopeResolver;
use App\Modules\MRP\Services\CapacityPlanningService;
use App\Modules\MRP\Listeners\QueueMrpOnSalesOrderConfirmed;
use App\Modules\MRP\Listeners\QueueMrpOnStockMovementCompleted;
use App\Modules\MRP\Enums\MrpRunTrigger;
use App\Modules\MRP\Models\MrpRun;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class MrpAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_order_confirmation_queues_scoped_automatic_mrp(): void
    {
        Queue::fake();
        $salesOrder = new SalesOrder();
        $salesOrder->forceFill(['id' => 42]);

        (new QueueMrpOnSalesOrderConfirmed())->handle(new SalesOrderConfirmed($salesOrder));

        Queue::assertPushed(RunAutomaticMrpJob::class, function (RunAutomaticMrpJob $job) use ($salesOrder): bool {
            return $job->salesOrderIds === [$salesOrder->id]
                && $job->reason === 'sales_order_confirmed';
        });
    }

    public function test_duplicate_automatic_requests_share_one_unique_queue_job(): void
    {
        Queue::fake();

        RunAutomaticMrpJob::dispatch([42], 'bom_changed');
        RunAutomaticMrpJob::dispatch([42], 'bom_changed');

        Queue::assertPushed(RunAutomaticMrpJob::class, 1);
    }

    public function test_stock_change_queues_only_sales_orders_affected_by_the_item(): void
    {
        Queue::fake();
        $movement = new StockMovement();
        $movement->forceFill(['id' => 7, 'item_id' => 88]);

        $resolver = Mockery::mock(MrpScopeResolver::class);
        $resolver->shouldReceive('salesOrderIdsForItems')->once()->with([88])->andReturn([12, 13]);
        app()->instance(MrpScopeResolver::class, $resolver);

        (new QueueMrpOnStockMovementCompleted())->handle(new StockMovementCompleted($movement));

        Queue::assertPushed(RunAutomaticMrpJob::class, function (RunAutomaticMrpJob $job): bool {
            return $job->salesOrderIds === [12, 13]
                && $job->reason === 'inventory_changed';
        });
    }

    public function test_mrp_replan_event_is_supported_by_the_durable_event_codec(): void
    {
        $codec = app(\App\Common\Services\OutboxEventCodec::class);
        $event = new MrpReplanRequested([21, 22], 'bom_changed');

        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);

        $this->assertInstanceOf(MrpReplanRequested::class, $decoded);
        $this->assertSame([21, 22], $decoded->salesOrderIds);
        $this->assertSame('bom_changed', $decoded->reason);
    }

    public function test_automatic_run_raises_idempotent_alerts_for_shortages_and_schedule_conflicts(): void
    {
        $run = MrpRun::create([
            'run_at' => now(),
            'triggered_by' => MrpRunTrigger::Automatic->value,
            'status' => 'completed',
        ]);
        $salesOrder = SalesOrder::factory()->create(['status' => 'confirmed']);
        $workOrder = WorkOrder::factory()->create([
            'sales_order_id' => $salesOrder->id,
            'status' => 'planned',
        ]);

        $engine = Mockery::mock(MrpEngineService::class);
        $engine->shouldReceive('runForActiveSalesOrders')->once()->andReturn($run);
        $planner = Mockery::mock(CapacityPlanningService::class);
        $planner->shouldReceive('run')->once()->with([$workOrder->id])->andReturn([
            'scheduled' => [],
            'conflicts' => [['wo_number' => $workOrder->wo_number, 'reasons' => ['no_mold_with_capacity']]],
        ]);
        $alerts = Mockery::mock(AlertEngineService::class);
        $alerts->shouldReceive('raise')->twice()->withArgs(function (
            AlertType $type,
            AlertSeverity $severity,
        ): bool {
            return in_array($type, [AlertType::MrpShortage, AlertType::MrpScheduleConflict], true)
                && $severity === AlertSeverity::Warning;
        });

        $service = new MrpAutomationService($engine, $planner, $alerts);
        $result = $service->run([$salesOrder->id], MrpRunTrigger::Automatic, null, 'test');

        $this->assertSame('completed', $result->status?->value);
        $this->assertSame(1, count($result->summary['scheduling']['conflicts']));
    }
}
