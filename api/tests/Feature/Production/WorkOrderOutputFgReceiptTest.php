<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Models\ChainListenerRun;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\ProductionReceiptHandoffStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\ProductionReceiptRequested;
use App\Modules\Production\Listeners\CreateProductionReceiptOnOutputRequested;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Production\Services\WorkOrderOutputService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->first();

        $this->assertNotNull($movement, 'A ProductionReceipt movement must exist after WO output recording');
        $this->assertSame('10.000', (string) $movement->quantity);
        $this->assertSame(ProductionReceiptHandoffStatus::Generated, $output->fresh()->production_receipt_handoff_status);
        $this->assertSame($movement->id, $output->fresh()->production_receipt_movement_id);
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
        $this->assertSame(ProductionReceiptHandoffStatus::NotRequired, $output->production_receipt_handoff_status);

        $count = StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count();

        $this->assertSame(0, $count, 'No stock movement for reject-only output');
    }

    public function test_missing_fg_item_commits_output_and_creates_durable_manual_handoff(): void
    {
        // Item codes are globally unique even across soft-deleted rows; this
        // test deliberately removes the prerequisite before restoring it.
        $this->fgItem->forceDelete();

        $output = $this->service->record($this->wo, [
            'good_count'   => 5,
            'reject_count' => 0,
        ], $this->user->id);

        $this->assertNotNull($output->id);
        $this->assertSame(ProductionReceiptHandoffStatus::ManualRequired, $output->fresh()->production_receipt_handoff_status);
        $this->assertNotEmpty($output->fresh()->production_receipt_handoff_message);

        $count = StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count();

        $this->assertSame(0, $count, 'No movement is posted until the inventory prerequisite is fixed');
        $request = DB::table('event_outbox')
            ->where('event_type', ProductionReceiptRequested::class)
            ->where('dedupe_key', 'production-receipt-request:'.$output->id)
            ->first();
        $this->assertNotNull($request, 'A missing FG item must create a durable recovery request.');
        $this->assertSame('published', $request->status);
        $this->assertDatabaseHas('chain_step_runs', [
            'outbox_id' => $request->id,
            'entity_type' => 'work_order',
            'entity_id' => $this->wo->id,
            'step' => 'production_receipt',
        ]);
    }

    public function test_missing_fg_location_commits_output_and_creates_durable_manual_handoff(): void
    {
        // Remove all FG-zone locations.
        WarehouseLocation::query()->where('zone_id', $this->fgLocation->zone_id)->delete();
        WarehouseZone::query()->where('zone_type', WarehouseZoneType::FinishedGoods->value)->delete();

        $output = $this->service->record($this->wo, [
            'good_count'   => 7,
            'reject_count' => 0,
        ], $this->user->id);

        $this->assertNotNull($output->id);
        $this->assertSame(ProductionReceiptHandoffStatus::ManualRequired, $output->fresh()->production_receipt_handoff_status);

        $count = StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count();

        $this->assertSame(0, $count, 'No movement is posted until the inventory prerequisite is fixed');
    }

    public function test_receipt_replay_after_fixing_item_is_idempotent(): void
    {
        $this->fgItem->forceDelete();

        $output = $this->service->record($this->wo, [
            'good_count' => 5,
            'reject_count' => 0,
        ], $this->user->id);

        $request = DB::table('event_outbox')
            ->where('event_type', ProductionReceiptRequested::class)
            ->where('dedupe_key', 'production-receipt-request:'.$output->id)
            ->firstOrFail();

        $replacement = Item::factory()->create([
            'code' => $this->product->part_number,
            'item_type' => ItemType::FinishedGood->value,
            'name' => 'Recovered FG Item',
        ]);

        $event = app(OutboxEventCodec::class)->decode(
            (string) $request->event_type,
            json_decode((string) $request->payload, true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertInstanceOf(ProductionReceiptRequested::class, $event);
        $listener = app(CreateProductionReceiptOnOutputRequested::class);
        $listener->handle($event);

        $recovered = $output->fresh();
        $this->assertSame(ProductionReceiptHandoffStatus::Generated, $recovered->production_receipt_handoff_status);
        $this->assertNotNull($recovered->production_receipt_movement_id);
        $movementCount = StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count();
        $this->assertSame(1, $movementCount);

        // A duplicate event/replay observes the exact output-level receipt and
        // must not add stock a second time.
        $listener->handle($event);
        $this->assertSame($movementCount, StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count());
        $this->assertSame(5, (int) StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->sum('quantity'));
        $this->assertSame($replacement->id, (int) StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->value('item_id'));

        $role = Role::create([
            'name' => 'Production Receipt Retry '.uniqid(),
            'slug' => 'production_receipt_retry_'.uniqid(),
            'description' => 'Test role',
        ]);
        $permission = Permission::firstOrCreate(
            ['slug' => 'production.wo.record'],
            ['name' => 'Record Production Output', 'module' => 'production'],
        );
        $role->permissions()->sync([$permission->id]);
        $this->user->update(['role_id' => $role->id]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/production/work-orders/{$this->wo->hash_id}/outputs/{$output->hash_id}/retry-receipt",
        );
        $response->assertOk()->assertJsonPath('data.production_receipt_handoff.status', 'generated');
        $this->assertSame($movementCount, StockMovement::query()
            ->where('reference_type', 'work_order_output')
            ->where('reference_id', $output->id)
            ->count());
    }
}
