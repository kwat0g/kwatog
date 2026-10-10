<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\QualityStockHoldService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HasPassedInProcessQc;
use Tests\TestCase;

class QualityHeldStockAdjustmentGuardTest extends TestCase
{
    use RefreshDatabase;
    use HasPassedInProcessQc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, ChartOfAccountsSeeder::class, SettingsSeeder::class]);
    }

    public function test_stock_adjustment_cannot_write_off_qc_held_quantity(): void
    {
        $warehouse = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
            'is_active' => true,
        ]);
        $qcMaker = User::factory()->create();
        $qcReviewer = User::factory()->create();
        $lotNumber = 'QC-ADJUST-'.strtoupper(substr(uniqid(), -6));
        $item = Item::factory()->create(['item_type' => ItemType::FinishedGood]);
        $product = Product::factory()->create();
        $workOrder = WorkOrder::factory()->create([
            'product_id' => $product->id,
            'status' => 'closed',
            'quantity_target' => 100,
            'quantity_produced' => 100,
            'quantity_good' => 100,
            'created_by' => $qcMaker->id,
        ]);
        $this->passInProcessQc($workOrder);
        $output = WorkOrderOutput::create([
            'work_order_id' => $workOrder->id,
            'recorded_by' => $qcMaker->id,
            'recorded_at' => now(),
            'good_count' => 100,
            'reject_count' => 0,
            'batch_code' => $lotNumber,
        ]);
        Inspection::create([
            'inspection_number' => 'QC-ADJUST-'.strtoupper(substr(uniqid(), -6)),
            'stage' => InspectionStage::Outgoing->value,
            'status' => InspectionStatus::Passed->value,
            'product_id' => $product->id,
            'entity_type' => InspectionEntityType::WorkOrder->value,
            'entity_id' => $workOrder->id,
            'work_order_output_id' => $output->id,
            'batch_quantity' => 100,
            'sample_size' => 20,
            'accepted_quantity' => 98,
            'inspector_id' => $qcMaker->id,
            'reviewed_by' => $qcReviewer->id,
            'reviewed_at' => now(),
            'completed_at' => now(),
        ]);

        $zone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $receipt = StockMovement::create([
            'movement_type' => 'production_receipt',
            'item_id' => $item->id,
            'quantity' => '100.000',
            'unit_cost' => '5.00',
            'total_cost' => '500.00',
            'to_location_id' => $location->id,
            'reference_type' => 'work_order_output',
            'reference_id' => $output->id,
            'created_by' => $qcMaker->id,
            'lot_number' => $lotNumber,
        ]);
        $output->forceFill(['production_receipt_movement_id' => $receipt->id])->save();
        StockLevel::create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '100.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '5.00',
        ]);
        $this->assertSame('2.000', app(QualityStockHoldService::class)->heldQuantity($item->id, $location->id, $lotNumber));

        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 0);
        $movementCount = StockMovement::query()->count();
        $adjustmentCount = StockAdjustment::query()->count();
        $adjustmentAuditCount = AuditLog::query()
            ->where('model_type', (new StockAdjustment)->getMorphClass())
            ->count();
        $response = $this->actingAs($warehouse)->postJson('/api/v1/inventory/stock-adjustments', [
            'item_id' => $item->hash_id,
            'location_id' => $location->hash_id,
            'direction' => 'out',
            'quantity' => '99.000',
            'reason' => 'Write off stock that includes the held quantity.',
            'reference_type' => 'work_order_output',
            'reference_id' => $output->id,
        ]);

        $this->assertSame(422, $response->status(), sprintf(
            'An adjustment must not consume the 2.000 QC-held units; got %d, on-hand %s, %d movements, and %d adjustments.',
            $response->status(),
            StockLevel::query()->where('item_id', $item->id)->where('location_id', $location->id)->value('quantity'),
            StockMovement::query()->count(),
            StockAdjustment::query()->count(),
        ));
        $response->assertJsonPath(
            'message',
            'Stock adjustment would consume QC-held stock; adjust only the unheld quantity or complete its Quality disposition first.',
        );
        $this->assertSame('100.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $location->id)->value('quantity'));
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame($adjustmentCount, StockAdjustment::query()->count());
        $this->assertSame($adjustmentAuditCount, AuditLog::query()
            ->where('model_type', (new StockAdjustment)->getMorphClass())
            ->count());
        $this->assertSame('2.000', app(QualityStockHoldService::class)->heldQuantity($item->id, $location->id, $lotNumber));

        $allowed = $this->actingAs($warehouse)->postJson('/api/v1/inventory/stock-adjustments', [
            'item_id' => $item->hash_id,
            'location_id' => $location->hash_id,
            'direction' => 'out',
            'quantity' => '98.000',
            'reason' => 'Write off only stock outside the Quality hold.',
        ]);

        $allowed->assertCreated();
        $this->assertSame('2.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $location->id)->value('quantity'));
        $this->assertSame($movementCount + 1, StockMovement::query()->count());
        $this->assertSame($adjustmentCount + 1, StockAdjustment::query()->count());
        $this->assertSame('2.000', app(QualityStockHoldService::class)->heldQuantity($item->id, $location->id, $lotNumber));
    }
}
