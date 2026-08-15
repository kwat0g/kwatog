<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\BomService;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderMaterial;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * F-10 — MRP nets against pooled on-hand, but WO confirm used to require a
 * single location to cover each BOM line. This test locks in the split:
 * when no location alone covers the demand, the reservation is split across
 * the locations with the most available stock.
 */
class WorkOrderSplitReservationTest extends TestCase
{
    use RefreshDatabase;

    private WorkOrderService $service;
    private User $user;
    private Product $product;
    private Item $item;
    private WarehouseLocation $locA;
    private WarehouseLocation $locB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WorkOrderService::class);
        $this->user    = User::factory()->create();
        $this->product = Product::create([
            'part_number'     => 'SPLIT-1',
            'name'            => 'Split Product',
            'unit_of_measure' => 'pcs',
            'standard_cost'   => 10.00,
            'is_active'       => true,
        ]);

        $this->item = Item::factory()->create(['name' => 'Split Material']);
        $this->locA = WarehouseLocation::factory()->create(['code' => 'SPLIT-A']);
        $this->locB = WarehouseLocation::factory()->create(['code' => 'SPLIT-B']);
    }

    private function seedStock(int $locationId, string $qty): void
    {
        StockLevel::create([
            'item_id'           => $this->item->id,
            'location_id'       => $locationId,
            'quantity'          => $qty,
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '10.0000',
        ]);
    }

    private function plannedWo(): WorkOrder
    {
        $wo = WorkOrder::factory()->create([
            'product_id'    => $this->product->id,
            'status'        => WorkOrderStatus::Planned->value,
            'planned_start' => Carbon::today()->addDay()->toDateTimeString(),
            'planned_end'   => Carbon::today()->addDays(2)->toDateTimeString(),
            'created_by'    => $this->user->id,
        ]);

        WorkOrderMaterial::create([
            'work_order_id'         => $wo->id,
            'item_id'               => $this->item->id,
            'bom_quantity'          => '30.000',
            'actual_quantity_issued' => '0.000',
            'variance'              => '0.000',
        ]);

        return $wo;
    }

    private function machine(): \App\Modules\MRP\Models\Machine
    {
        return \App\Modules\MRP\Models\Machine::factory()->create(['status' => 'idle']);
    }

    private function mold(): Mold
    {
        return Mold::create([
            'mold_code'                    => 'MD-' . substr(uniqid(), -5),
            'name'                         => 'Split Mold',
            'product_id'                   => $this->product->id,
            'cavity_count'                 => 1,
            'cycle_time_seconds'           => 30,
            'output_rate_per_hour'         => 100,
            'setup_time_minutes'           => 10,
            'current_shot_count'           => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots'           => 1000000,
            'status'                       => 'available',
        ]);
    }

    public function test_confirm_splits_reservation_across_locations(): void
    {
        $this->seedStock($this->locA->id, '20.000');
        $this->seedStock($this->locB->id, '15.000');

        $machine = $this->machine();
        $wo = $this->plannedWo();
        $mold = $this->mold();
        $mold->compatibleMachines()->syncWithoutDetaching([$machine->id]);

        $confirmed = $this->service->confirm($wo, $machine->id, $mold->id);

        $this->assertSame(WorkOrderStatus::Confirmed, $confirmed->status);

        $reservations = MaterialReservation::where('work_order_id', $wo->id)
            ->where('status', ReservationStatus::Reserved->value)
            ->orderBy('location_id')
            ->get();

        $this->assertCount(2, $reservations, 'Demand split across two locations');
        $this->assertSame('20.000', (string) $reservations[0]->quantity);
        $this->assertSame('10.000', (string) $reservations[1]->quantity);
        $this->assertSame($this->locA->id, $reservations[0]->location_id);
        $this->assertSame($this->locB->id, $reservations[1]->location_id);

        foreach ($reservations as $res) {
            $level = StockLevel::where('item_id', $this->item->id)
                ->where('location_id', $res->location_id)
                ->first();
            $this->assertSame((string) $res->quantity, (string) $level->reserved_quantity);
        }
    }

    public function test_work_order_material_snapshots_standard_cost_and_accumulates_actual_issue_cost(): void
    {
        $this->item->forceFill(['standard_cost' => '10.0000'])->save();
        app(BomService::class)->create($this->product->id, [[
            'item_id' => $this->item->id,
            'quantity_per_unit' => '2.0000',
            'unit' => $this->item->unit_of_measure,
            'waste_factor' => '0.00',
        ]]);

        $wo = app(WorkOrderService::class)->createDraft([
            'product_id' => $this->product->id,
            'quantity_target' => 15,
            'planned_start' => Carbon::today()->addDay()->toDateTimeString(),
            'planned_end' => Carbon::today()->addDays(2)->toDateTimeString(),
            'created_by' => $this->user->id,
        ]);

        $material = $wo->materials->firstOrFail();
        $this->assertSame('10.0000', (string) $material->standard_unit_cost);
        $this->assertSame('300.00', (string) $material->standard_cost);
        $this->assertSame('-300.00', (string) $material->cost_variance);

        $this->seedStock($this->locA->id, '30.000');
        StockLevel::query()->where('item_id', $this->item->id)->where('location_id', $this->locA->id)->update([
            'weighted_avg_cost' => '12.0000',
        ]);
        $machine = $this->machine();
        $mold = $this->mold();
        $mold->compatibleMachines()->syncWithoutDetaching([$machine->id]);

        $confirmed = app(WorkOrderService::class)->confirm($wo, $machine->id, $mold->id);
        app(WorkOrderService::class)->start($confirmed);

        $material = $wo->materials()->firstOrFail()->fresh();
        $this->assertSame('30.000', (string) $material->actual_quantity_issued);
        $this->assertSame('360.00', (string) $material->actual_cost);
        $this->assertSame('60.00', (string) $material->cost_variance);
    }

    public function test_confirm_uses_single_location_when_it_covers_demand(): void
    {
        $this->seedStock($this->locA->id, '50.000');
        $this->seedStock($this->locB->id, '5.000');

        $machine = $this->machine();
        $wo = $this->plannedWo();
        $mold = $this->mold();
        $mold->compatibleMachines()->syncWithoutDetaching([$machine->id]);

        $this->service->confirm($wo, $machine->id, $mold->id);

        $reservations = MaterialReservation::where('work_order_id', $wo->id)->get();
        $this->assertCount(1, $reservations, 'Single location covers demand — no split');
        $this->assertSame($this->locA->id, $reservations[0]->location_id);
    }

    public function test_confirm_fails_when_pooled_stock_is_insufficient(): void
    {
        $this->seedStock($this->locA->id, '20.000');
        $this->seedStock($this->locB->id, '5.000');

        $machine = $this->machine();
        $wo = $this->plannedWo();
        $mold = $this->mold();
        $mold->compatibleMachines()->syncWithoutDetaching([$machine->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock for item');

        try {
            $this->service->confirm($wo, $machine->id, $mold->id);
        } finally {
            // Transaction rolled back — nothing reserved.
            $this->assertSame(0, MaterialReservation::where('work_order_id', $wo->id)->count());
        }
    }
}
