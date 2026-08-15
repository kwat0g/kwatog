<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Services\AlertEngineService;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
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
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
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

        app(QueueMrpOnStockMovementCompleted::class)->handle(new StockMovementCompleted($movement));

        Queue::assertPushed(RunAutomaticMrpJob::class, function (RunAutomaticMrpJob $job): bool {
            return $job->salesOrderIds === [12, 13]
                && $job->reason === 'inventory_changed';
        });
    }

    public function test_subassembly_scope_resolves_parent_sales_orders(): void
    {
        $child = Product::factory()->create([
            'part_number' => 'SUB-' . strtoupper(substr(uniqid(), -6)),
        ]);
        $parent = Product::factory()->create();
        $childItem = Item::factory()->create([
            'code' => $child->part_number,
            'unit_of_measure' => 'pcs',
            'item_type' => 'finished_good',
        ]);
        $bom = Bom::create([
            'product_id' => $parent->id,
            'version' => 1,
            'is_active' => true,
        ]);
        BomItem::create([
            'bom_id' => $bom->id,
            'item_id' => $childItem->id,
            'quantity_per_unit' => '1.0000',
            'unit' => 'pcs',
            'waste_factor' => '0.00',
            'sort_order' => 0,
        ]);
        $salesOrder = SalesOrder::factory()->create(['status' => 'confirmed']);
        SalesOrderItem::factory()->create([
            'sales_order_id' => $salesOrder->id,
            'product_id' => $parent->id,
        ]);

        $this->assertSame(
            [$salesOrder->id],
            app(MrpScopeResolver::class)->salesOrderIdsForProduct($child->id),
        );
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
        $salesOrder = SalesOrder::factory()->create(['status' => 'confirmed']);
        $run = MrpRun::create([
            'run_at' => now(),
            'triggered_by' => MrpRunTrigger::Automatic->value,
            'status' => 'completed',
            'shortages_found' => 1,
            'summary' => [
                'per_sales_order' => [
                    ['so_id' => $salesOrder->id, 'shortages_found' => 1, 'plan_no' => 'MRP-AUTO-TEST'],
                    ['so_id' => 999, 'error' => 'Circular bill of materials detected.'],
                ],
            ],
        ]);
        $plan = MrpPlan::create([
            'mrp_plan_no' => 'MRP-AUTO-TEST',
            'sales_order_id' => $salesOrder->id,
            'version' => 1,
            'status' => 'active',
            'generated_by' => $salesOrder->created_by,
            'generated_at' => now(),
        ]);
        $workOrder = WorkOrder::factory()->create([
            'sales_order_id' => $salesOrder->id,
            'mrp_plan_id' => $plan->id,
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
        $alerts->shouldReceive('raise')->times(3)->withArgs(function (
            AlertType $type,
            AlertSeverity $severity,
        ): bool {
            return in_array($type, [
                AlertType::MrpShortage,
                AlertType::MrpScheduleConflict,
                AlertType::MrpDataError,
            ], true)
                && $severity === AlertSeverity::Warning;
        });

        $service = new MrpAutomationService($engine, $planner, $alerts);
        $result = $service->run([$salesOrder->id], MrpRunTrigger::Automatic, null, 'test');

        $this->assertSame('completed', $result->status?->value);
        $this->assertSame(1, count($result->summary['scheduling']['conflicts']));
    }
}
