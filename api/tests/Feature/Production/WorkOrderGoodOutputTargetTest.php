<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Services\InspectionSpecService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\HasAssignedProductionCrew;
use Tests\Support\HasPassedInProcessQc;

/**
 * O2C audit 2026-09-25 — a work order's target is the GOOD quantity the order
 * needs, and a WO that produced nothing good cannot be marked completed.
 *
 * Before: rejects consumed the target (a 150-piece WO with 3 rejects could
 * deliver only 147, forcing a 3-piece tail WO), and a WO completed with zero
 * output left the order line short with nothing for outgoing QC to inspect.
 */
class WorkOrderGoodOutputTargetTest extends TestCase
{
    use RefreshDatabase;
    use HasAssignedProductionCrew;
    use HasPassedInProcessQc;

    private User $user;

    private DefectType $defectType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ChartOfAccountsSeeder::class, SettingsSeeder::class]);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $warehouse = Warehouse::factory()->create();
        $zone = WarehouseZone::factory()->create([
            'warehouse_id' => $warehouse->id,
            'zone_type' => WarehouseZoneType::FinishedGoods->value,
            'code' => 'FGTGT',
            'name' => 'Finished Goods',
        ]);
        WarehouseLocation::factory()->create([
            'zone_id' => $zone->id,
            'code' => 'FGTGT-LOC',
            'is_active' => true,
        ]);
        $this->defectType = DefectType::create([
            'code' => 'DT-TGT',
            'name' => 'Target test defect',
            'description' => null,
            'is_active' => true,
        ]);
    }

    private function makeWo(int $target, array $overrides = []): WorkOrder
    {
        $product = Product::create([
            'part_number' => 'FG-TGT-'.substr(uniqid(), -5),
            'name' => 'Target FG',
            'unit_of_measure' => 'pcs',
            'standard_cost' => 10.00,
            'is_active' => true,
        ]);
        Item::factory()->create([
            'code' => $product->part_number,
            'item_type' => ItemType::FinishedGood->value,
            'name' => 'Finished good for '.$product->part_number,
            'unit_of_measure' => $product->unit_of_measure,
        ]);
        app(InspectionSpecService::class)->upsertForProduct($product->id, [
            ['parameter_name' => 'Surface condition', 'parameter_type' => 'visual', 'is_critical' => false],
        ], $this->user->id);
        $machine = Machine::factory()->create();
        $mold = Mold::create([
            'mold_code' => 'TGT-'.substr(uniqid(), -5),
            'name' => 'Target test mold',
            'product_id' => $product->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
        $mold->compatibleMachines()->sync([$machine->id]);

        $workOrder = WorkOrder::create(array_merge([
            'wo_number' => 'WO-TGT-'.substr(uniqid(), -5),
            'product_id' => $product->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'status' => WorkOrderStatus::InProgress->value,
            'quantity_target' => $target,
            'quantity_produced' => 0,
            'quantity_good' => 0,
            'quantity_rejected' => 0,
            'planned_start' => now(),
            'planned_end' => now()->addDay(),
            'actual_start' => now()->subHour(),
            'created_by' => $this->user->id,
        ], $overrides));
        $machine->update(['status' => 'running', 'current_work_order_id' => $workOrder->id]);
        $mold->update(['status' => 'in_use', 'current_work_order_id' => $workOrder->id]);
        ProductionSchedule::create([
            'work_order_id' => $workOrder->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'scheduled_start' => now()->subMinutes(30),
            'scheduled_end' => now()->addHours(2),
            'priority_order' => 0,
            'status' => 'confirmed',
            'is_confirmed' => true,
            'confirmed_by' => $this->user->id,
            'confirmed_at' => now(),
            'reserves_capacity' => true,
        ]);
        $this->assignProductionCrew($workOrder, $this->user);
        $this->passInProcessQc($workOrder);

        return $workOrder;
    }

    public function test_rejects_do_not_use_up_the_good_target(): void
    {
        $wo = $this->makeWo(150);

        $output = app(WorkOrderOutputService::class)->record($wo, $this->outputForCrew($wo, [
            'good_count' => 150,
            'reject_count' => 3,
            'defects' => [['defect_type_id' => $this->defectType->id, 'count' => 3]],
        ], $this->user), $this->user->id);

        $fresh = $wo->fresh();
        $this->assertSame(150, (int) $fresh->quantity_good);
        $this->assertSame(3, (int) $fresh->quantity_rejected);
        $this->assertSame(153, (int) $fresh->quantity_produced);
        $movement = StockMovement::query()->findOrFail($output->production_receipt_movement_id);
        $this->assertTrue($movement->gl_handoff_status?->isResolved());
    }

    public function test_good_output_beyond_the_target_is_refused(): void
    {
        $wo = $this->makeWo(150);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('target of 150 good pieces');

        app(WorkOrderOutputService::class)->record($wo, $this->outputForCrew($wo, ['good_count' => 151, 'reject_count' => 0], $this->user), $this->user->id);
    }

    public function test_rejected_only_run_can_finish_with_explicit_shortfall_reason(): void
    {
        $wo = $this->makeWo(150);
        app(WorkOrderOutputService::class)->record($wo, $this->outputForCrew($wo, [
            'good_count' => 0, 'reject_count' => 3,
            'defects' => [['defect_type_id' => $this->defectType->id, 'count' => 3]],
        ], $this->user), $this->user->id);
        $finished = app(WorkOrderService::class)->complete($wo->fresh(), 'Run stopped after rejected output; replan remaining demand.');
        $this->assertSame('closed', $finished->status->value);
        $this->assertSame(0, (int) $finished->quantity_good);
        $this->assertNotEmpty($finished->short_completion_reason);
    }

    public function test_a_work_order_without_good_output_cannot_be_completed(): void
    {
        $wo = $this->makeWo(150);

        try {
            app(WorkOrderService::class)->complete($wo);
            $this->fail('Completing a WO with no good output must be refused.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('no good output to complete', $e->getMessage());
        }

        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
    }

    public function test_a_work_order_with_good_output_completes(): void
    {
        $wo = $this->makeWo(10);
        $output = app(WorkOrderOutputService::class)->record($wo, $this->outputForCrew($wo, ['good_count' => 10, 'reject_count' => 0], $this->user), $this->user->id);
        $movement = StockMovement::query()->findOrFail($output->production_receipt_movement_id);
        $this->assertTrue($movement->gl_handoff_status?->isResolved());

        app(WorkOrderService::class)->complete($wo->fresh());

        $this->assertSame(WorkOrderStatus::Completed, $wo->fresh()->status);
    }
}
