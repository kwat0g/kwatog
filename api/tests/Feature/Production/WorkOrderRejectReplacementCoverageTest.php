<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\MaterialIssueService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Enums\WoOperationStatus;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderMaterial;
use App\Modules\Production\Services\WoOperationService;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WorkOrderRejectReplacementCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_reported_reject_is_accepted_but_later_replacement_output_requires_incremental_material(): void
    {
        Event::fake();
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $item = Item::factory()->create(['unit_of_measure' => 'kg']);
        $location = WarehouseLocation::factory()->create();
        StockLevel::create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '100.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '12.3400',
        ]);

        $workOrder = WorkOrder::factory()->create([
            'product_id' => $product->id,
            'status' => WorkOrderStatus::Planned->value,
            'quantity_target' => 5,
            'planned_start' => Carbon::today()->addDay()->toDateTimeString(),
            'planned_end' => Carbon::today()->addDays(2)->toDateTimeString(),
            'work_order_class' => 'standard',
            'material_plan_source' => 'bom',
            'created_by' => $user->id,
        ]);
        WorkOrderMaterial::create([
            'work_order_id' => $workOrder->id,
            'item_id' => $item->id,
            'bom_quantity' => '5.000',
            'standard_unit_cost' => '10.0000',
            'standard_cost' => '50.00',
            'actual_quantity_issued' => '0.000',
            'actual_cost' => '0.00',
            'cost_variance' => '-50.00',
            'variance' => '0.000',
        ]);

        $machine = Machine::factory()->create(['status' => 'idle']);
        $mold = Mold::create([
            'mold_code' => 'RCM-'.substr(uniqid(), -5),
            'name' => 'Reject coverage mold',
            'product_id' => $product->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 25,
            'output_rate_per_hour' => 120,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
        $mold->compatibleMachines()->syncWithoutDetaching([$machine->id]);
        $defectType = DefectType::create([
            'code' => 'RC'.substr(uniqid(), -5),
            'name' => 'Reject coverage test defect',
            'description' => null,
            'is_active' => true,
        ]);

        $workOrders = app(WorkOrderService::class);
        $started = $workOrders->start(
            $workOrders->confirm($workOrder, $machine->id, $mold->id),
            $user->id,
        );
        $outputs = app(WorkOrderOutputService::class);

        $first = $outputs->record($started, [
            'good_count' => 3,
            'reject_count' => 1,
            'defects' => [['defect_type_id' => $defectType->id, 'count' => 1]],
        ], $user->id, 'reject-coverage-first-output');

        $this->assertSame(3, (int) $first->good_count);
        $this->assertSame(1, (int) $first->reject_count);
        $this->assertSame(4, (int) $started->fresh()->quantity_produced);

        // Routed operation output records the same physical units as canonical
        // output. It must not be added a second time to the consumption floor.
        $operation = WoOperation::create([
            'work_order_id' => $started->id,
            'sequence' => 1,
            'operation_name' => 'Injection',
            'status' => WoOperationStatus::InProgress,
            'qty_planned' => '5.0000',
            'qty_completed' => '0.0000',
            'qty_scrapped' => '0.0000',
            'downtime_minutes' => 0,
        ]);
        $operations = app(WoOperationService::class);
        $operations->recordOutput($operation, 2);
        $operations->recordOutput($operation->fresh(), 2);
        try {
            $operations->recordOutput($operation->fresh(), 2);
            $this->fail('A routed operation growing beyond canonical output must require replacement material.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('1.000', $e->getMessage());
        }
        $this->assertSame('4.0000', (string) $operation->fresh()->qty_completed);

        try {
            $outputs->record($started->fresh(), [
                'good_count' => 2,
                'reject_count' => 0,
                'defects' => [],
            ], $user->id, 'reject-coverage-final-output-before-issue');
            $this->fail('Recording two more pieces must require six total material units after five were issued for the target.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('1.000', $e->getMessage());
        }

        $this->assertSame(1, $started->outputs()->count(), 'A blocked replacement must not create a partial output row.');
        $this->assertSame(4, (int) $started->fresh()->quantity_produced);
        $this->assertSame(3, (int) $started->fresh()->quantity_good);
        $this->assertSame(1, (int) $started->fresh()->quantity_rejected);

        app(MaterialIssueService::class)->create([
            'work_order_id' => $started->hash_id,
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $item->hash_id,
                'location_id' => $location->hash_id,
                'quantity_issued' => '1.000',
            ]],
        ], $user);

        $operations->recordOutput($operation->fresh(), 2);

        $last = $outputs->record($started->fresh(), [
            'good_count' => 2,
            'reject_count' => 0,
            'defects' => [],
        ], $user->id, 'reject-coverage-final-output-after-issue');
        $workOrder = $started->fresh();

        $this->assertSame(2, (int) $last->good_count);
        $this->assertSame(5, (int) $workOrder->quantity_target, 'Rejects do not consume the good-output target.');
        $this->assertSame(6, (int) $workOrder->quantity_produced);
        $this->assertSame(5, (int) $workOrder->quantity_good);
        $this->assertSame(1, (int) $workOrder->quantity_rejected);
        $this->assertSame('5.000', (string) $workOrder->materials()->value('actual_quantity_issued'), 'Automatic counters remain auto-only.');
    }
}
