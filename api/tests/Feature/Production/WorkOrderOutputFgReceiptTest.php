<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderOutputService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderOutputFgReceiptTest extends TestCase
{
    use RefreshDatabase;

    private WorkOrderOutputService $service;
    private User $user;
    private Product $product;
    private WorkOrder $wo;
    private WarehouseLocation $fgLocation;
    private Item $fgItem;
    private DefectType $defectType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->product = Product::create([
            'part_number'     => 'FG-PTEST-01',
            'name'            => 'FG Product',
            'unit_of_measure' => 'pcs',
            'standard_cost'   => 50.00,
            'is_active'       => true,
        ]);

        // Create a FinishedGoods-zone location.
        $warehouse = Warehouse::factory()->create();
        $zone = WarehouseZone::factory()->create([
            'warehouse_id' => $warehouse->id,
            'zone_type'    => WarehouseZoneType::FinishedGoods->value,
            'code'         => 'FGZ',
            'name'         => 'Finished Goods Zone',
        ]);
        $this->fgLocation = WarehouseLocation::factory()->create([
            'zone_id'   => $zone->id,
            'code'      => 'FG-LOC-01',
            'is_active' => true,
        ]);

        // FG Item whose code matches the product part_number (convention).
        $this->fgItem = Item::factory()->create([
            'code'      => $this->product->part_number,
            'item_type' => ItemType::FinishedGood->value,
            'name'      => 'FG Item for ' . $this->product->part_number,
        ]);

        $this->wo = WorkOrder::create([
            'wo_number'      => 'WO-FG-' . substr(uniqid(), -5),
            'product_id'     => $this->product->id,
            'status'         => WorkOrderStatus::InProgress->value,
            'quantity_target' => 100,
            'quantity_produced' => 0,
            'quantity_good'  => 0,
            'quantity_rejected' => 0,
            'planned_start'  => now(),
            'planned_end'    => now()->addDay(),
            'actual_start'   => now()->subHour(),
            'machine_id'     => null,
            'created_by'     => $this->user->id,
        ]);

        $this->service = app(WorkOrderOutputService::class);

        $this->defectType = DefectType::create([
            'code'        => 'DT-FG-TEST',
            'name'        => 'Test Defect for FG',
            'description' => null,
            'is_active'   => true,
        ]);
    }

    public function test_record_output_creates_production_receipt_movement(): void
    {
        $output = $this->service->record($this->wo, [
            'good_count'   => 10,
            'reject_count' => 2,
            'defects' => [
                ['defect_type_id' => $this->defectType->id, 'count' => 2],
            ],
        ], $this->user->id);

        $this->assertNotNull($output->id);

        /** @var \App\Modules\Inventory\Models\StockMovement $movement */
        $movement = \App\Modules\Inventory\Models\StockMovement::query()
            ->where('item_id', $this->fgItem->id)
            ->where('to_location_id', $this->fgLocation->id)
            ->where('movement_type', StockMovementType::ProductionReceipt->value)
            ->where('reference_type', 'work_order')
            ->where('reference_id', $this->wo->id)
            ->first();

        $this->assertNotNull($movement, 'A ProductionReceipt movement must exist after WO output recording');
        $this->assertSame('10.000', (string) $movement->quantity);
    }

    public function test_reject_only_output_does_not_create_movement(): void
    {
        $output = $this->service->record($this->wo, [
            'good_count'   => 0,
            'reject_count' => 3,
            'defects' => [
                ['defect_type_id' => $this->defectType->id, 'count' => 3],
            ],
        ], $this->user->id);

        $this->assertNotNull($output->id);

        $count = \App\Modules\Inventory\Models\StockMovement::query()
            ->where('reference_type', 'work_order')
            ->where('reference_id', $this->wo->id)
            ->count();

        $this->assertSame(0, $count, 'No stock movement for reject-only output');
    }

    public function test_missing_fg_item_skips_movement_gracefully(): void
    {
        $this->fgItem->delete();

        $output = $this->service->record($this->wo, [
            'good_count'   => 5,
            'reject_count' => 0,
        ], $this->user->id);

        $this->assertNotNull($output->id);

        $count = \App\Modules\Inventory\Models\StockMovement::query()
            ->where('reference_type', 'work_order')
            ->where('reference_id', $this->wo->id)
            ->count();

        $this->assertSame(0, $count, 'No movement when FG item is missing');
    }

    public function test_missing_fg_location_skips_movement_gracefully(): void
    {
        // Remove all FG-zone locations.
        WarehouseLocation::query()->where('zone_id', $this->fgLocation->zone_id)->delete();
        WarehouseZone::query()->where('zone_type', WarehouseZoneType::FinishedGoods->value)->delete();

        $output = $this->service->record($this->wo, [
            'good_count'   => 7,
            'reject_count' => 0,
        ], $this->user->id);

        $this->assertNotNull($output->id);

        $count = \App\Modules\Inventory\Models\StockMovement::query()
            ->where('reference_type', 'work_order')
            ->where('reference_id', $this->wo->id)
            ->count();

        $this->assertSame(0, $count, 'No movement when FG location is missing');
    }
}