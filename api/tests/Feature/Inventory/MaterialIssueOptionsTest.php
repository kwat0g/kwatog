<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialIssueOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->warehouse = User::factory()->create([
            'role_id' => Role::where('slug', 'warehouse_staff')->value('id'),
        ]);
    }

    public function test_warehouse_can_get_supported_work_orders_and_only_usable_issue_sources(): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => WorkOrderStatus::Confirmed]);
        $inProgressWorkOrder = WorkOrder::factory()->create(['status' => WorkOrderStatus::InProgress]);
        $pausedWorkOrder = WorkOrder::factory()->create(['status' => WorkOrderStatus::Paused]);
        WorkOrder::factory()->create(['status' => WorkOrderStatus::Planned]);
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create(['code' => 'MISOPT']);
        $raw = WarehouseZone::factory()->create([
            'warehouse_id' => $warehouse->id,
            'zone_type' => 'raw_materials',
        ]);
        $good = WarehouseLocation::factory()->create(['zone_id' => $raw->id, 'code' => 'GOOD']);
        $blocked = WarehouseLocation::factory()->create(['zone_id' => $raw->id, 'code' => 'BLOCKED', 'is_blocked' => true]);
        $inactive = WarehouseLocation::factory()->create(['zone_id' => $raw->id, 'code' => 'INACTIVE', 'is_active' => false]);
        $quarantineZone = WarehouseZone::factory()->create([
            'warehouse_id' => $warehouse->id,
            'zone_type' => 'quarantine',
        ]);
        $quarantine = WarehouseLocation::factory()->create(['zone_id' => $quarantineZone->id, 'code' => 'QUAR']);
        $inactiveWarehouse = Warehouse::factory()->create(['code' => 'MISOFF', 'is_active' => false]);
        $inactiveWarehouseZone = WarehouseZone::factory()->create(['warehouse_id' => $inactiveWarehouse->id]);
        $offsite = WarehouseLocation::factory()->create(['zone_id' => $inactiveWarehouseZone->id, 'code' => 'OFF']);

        StockLevel::create(['item_id' => $item->id, 'location_id' => $good->id, 'quantity' => '20.000', 'reserved_quantity' => '20.000', 'weighted_avg_cost' => '12.5000']);
        StockLevel::create(['item_id' => $item->id, 'location_id' => $blocked->id, 'quantity' => '4.000', 'reserved_quantity' => '0.000', 'weighted_avg_cost' => '12.5000']);
        StockLevel::create(['item_id' => $item->id, 'location_id' => $inactive->id, 'quantity' => '4.000', 'reserved_quantity' => '0.000', 'weighted_avg_cost' => '12.5000']);
        StockLevel::create(['item_id' => $item->id, 'location_id' => $quarantine->id, 'quantity' => '4.000', 'reserved_quantity' => '0.000', 'weighted_avg_cost' => '12.5000']);
        StockLevel::create(['item_id' => $item->id, 'location_id' => $offsite->id, 'quantity' => '4.000', 'reserved_quantity' => '0.000', 'weighted_avg_cost' => '12.5000']);
        $reservation = MaterialReservation::create([
            'item_id' => $item->id,
            'work_order_id' => $workOrder->id,
            'location_id' => $good->id,
            'quantity' => '20.000',
            'status' => ReservationStatus::Reserved,
            'reserved_at' => now(),
        ]);

        $options = $this->actingAs($this->warehouse)->getJson('/api/v1/inventory/material-issues/options')
            ->assertOk()
            ->assertJsonMissingPath('data.work_orders.0.internal_id');
        $this->assertCount(3, $options->json('data.work_orders'));
        $optionIds = collect($options->json('data.work_orders'))->pluck('id')->all();
        $this->assertContains($workOrder->hash_id, $optionIds);
        $this->assertContains($inProgressWorkOrder->hash_id, $optionIds);
        $this->assertContains($pausedWorkOrder->hash_id, $optionIds);
        $this->assertSame(
            ['confirmed', 'in_progress', 'paused'],
            collect($options->json('data.work_orders'))->pluck('status')->sort()->values()->all(),
        );
        $this->actingAs($this->warehouse)
            ->getJson('/api/v1/production/work-orders?status=confirmed&per_page=100')
            ->assertForbidden();

        $context = $this->actingAs($this->warehouse)->getJson(
            '/api/v1/inventory/material-issues/options?work_order_id='.$workOrder->hash_id.'&item_id='.$item->hash_id,
        )->assertOk();
        $sourceIds = collect($context->json('data.sources'))->pluck('location_id')->all();
        $this->assertContains($good->hash_id, $sourceIds, 'Own reserved source must remain eligible at zero general availability.');
        $this->assertContains($blocked->hash_id, $sourceIds, 'Blocking is receipt-specific; stock can still be issued out.');
        $this->assertNotContains($inactive->hash_id, $sourceIds);
        $this->assertNotContains($quarantine->hash_id, $sourceIds);
        $this->assertNotContains($offsite->hash_id, $sourceIds);
        $this->assertSame($reservation->hash_id, $context->json('data.reservations.0.id'));
        $this->assertSame($reservation->reserved_at?->toIso8601String(), $context->json('data.reservations.0.reserved_at'));
        $this->assertArrayNotHasKey('internal_id', $context->json('data.reservations.0'));

        $employee = User::factory()->create(['role_id' => Role::where('slug', 'employee')->value('id')]);
        $this->actingAs($employee)->getJson('/api/v1/inventory/material-issues/options')->assertForbidden();
        $this->actingAs($this->warehouse)
            ->getJson('/api/v1/inventory/material-issues/options?item_id%5B%5D=invalid')
            ->assertUnprocessable();
    }

    public function test_reservation_hash_id_is_decoded_for_warehouse_issue_and_partial_remainder_is_preserved(): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => WorkOrderStatus::InProgress]);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        StockLevel::create(['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => '20.000', 'reserved_quantity' => '20.000', 'weighted_avg_cost' => '12.5000']);
        $reservation = MaterialReservation::create([
            'item_id' => $item->id,
            'work_order_id' => $workOrder->id,
            'location_id' => $location->id,
            'quantity' => '20.000',
            'status' => ReservationStatus::Reserved,
            'reserved_at' => now(),
        ]);

        $response = $this->actingAs($this->warehouse)->postJson('/api/v1/inventory/material-issues', [
            'work_order_id' => $workOrder->hash_id,
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $item->hash_id,
                'location_id' => $location->hash_id,
                'material_reservation_id' => $reservation->hash_id,
                'quantity_issued' => '5.000',
            ]],
        ])->assertCreated();

        $this->assertSame(1, MaterialIssueSlip::query()->count());
        $this->assertSame('15.000', (string) StockLevel::query()->where('item_id', $item->id)->where('location_id', $location->id)->value('quantity'));
        $this->assertSame('15.000', (string) StockLevel::query()->where('item_id', $item->id)->where('location_id', $location->id)->value('reserved_quantity'));
        $this->assertSame('15.000', (string) $reservation->fresh()->quantity);
        $this->assertDatabaseHas('material_issue_slip_items', [
            'material_reservation_id' => $reservation->id,
            'quantity_issued' => '5.000',
        ]);
        $this->assertSame($workOrder->hash_id, $response->json('data.work_order_id'));
        $this->actingAs($this->warehouse)->getJson('/api/v1/inventory/material-issues?per_page=100')
            ->assertOk()
            ->assertJsonPath('data.0.work_order.id', $workOrder->hash_id)
            ->assertJsonPath('data.0.work_order.wo_number', $workOrder->wo_number);
    }

    public function test_planned_completed_closed_and_cancelled_work_orders_cannot_receive_an_issue(): void
    {
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        StockLevel::create(['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => '5.000', 'reserved_quantity' => '0.000', 'weighted_avg_cost' => '12.5000']);

        foreach ([WorkOrderStatus::Planned, WorkOrderStatus::Completed, WorkOrderStatus::Closed, WorkOrderStatus::Cancelled] as $status) {
            $workOrder = WorkOrder::factory()->create(['status' => $status]);
            $this->actingAs($this->warehouse)->postJson('/api/v1/inventory/material-issues', [
                'work_order_id' => $workOrder->hash_id,
                'issued_date' => now()->toDateString(),
                'items' => [[
                    'item_id' => $item->hash_id,
                    'location_id' => $location->hash_id,
                    'quantity_issued' => '2.000',
                ]],
            ])->assertUnprocessable()->assertJsonPath('message', 'Materials can only be issued to confirmed, in-progress, or paused work orders.');
        }

        $this->assertSame('5.000', (string) StockLevel::query()->where('item_id', $item->id)->where('location_id', $location->id)->value('quantity'));
        $this->assertSame(0, MaterialIssueSlip::query()->count());
    }

    public function test_paused_work_order_can_receive_material_issue_and_is_available_in_options(): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => WorkOrderStatus::Paused]);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        StockLevel::create(['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => '5.000', 'reserved_quantity' => '0.000', 'weighted_avg_cost' => '12.5000']);

        $options = $this->actingAs($this->warehouse)->getJson('/api/v1/inventory/material-issues/options')
            ->assertOk();
        $this->assertContains($workOrder->hash_id, collect($options->json('data.work_orders'))->pluck('id')->all());

        $this->actingAs($this->warehouse)->postJson('/api/v1/inventory/material-issues', [
            'work_order_id' => $workOrder->hash_id,
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $item->hash_id,
                'location_id' => $location->hash_id,
                'quantity_issued' => '2.000',
            ]],
        ])->assertCreated();

        $this->assertSame('3.000', (string) StockLevel::query()->where('item_id', $item->id)->where('location_id', $location->id)->value('quantity'));
        $this->assertSame(1, MaterialIssueSlip::query()->where('work_order_id', $workOrder->id)->count());
    }

    public function test_reserved_and_general_stock_can_be_issued_as_separate_lines_from_one_source(): void
    {
        $workOrder = WorkOrder::factory()->create(['status' => WorkOrderStatus::Confirmed]);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        StockLevel::create(['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => '40.000', 'reserved_quantity' => '20.000', 'weighted_avg_cost' => '12.5000']);
        $reservation = MaterialReservation::create([
            'item_id' => $item->id,
            'work_order_id' => $workOrder->id,
            'location_id' => $location->id,
            'quantity' => '20.000',
            'status' => ReservationStatus::Reserved,
            'reserved_at' => now(),
        ]);

        $this->actingAs($this->warehouse)->postJson('/api/v1/inventory/material-issues', [
            'work_order_id' => $workOrder->hash_id,
            'issued_date' => now()->toDateString(),
            'items' => [
                [
                    'item_id' => $item->hash_id,
                    'location_id' => $location->hash_id,
                    'material_reservation_id' => $reservation->hash_id,
                    'quantity_issued' => '5.000',
                ],
                [
                    'item_id' => $item->hash_id,
                    'location_id' => $location->hash_id,
                    'quantity_issued' => '10.000',
                ],
            ],
        ])->assertCreated();

        $this->assertSame('25.000', (string) StockLevel::query()->where('item_id', $item->id)->where('location_id', $location->id)->value('quantity'));
        $this->assertSame('15.000', (string) StockLevel::query()->where('item_id', $item->id)->where('location_id', $location->id)->value('reserved_quantity'));
        $this->assertDatabaseHas('material_issue_slip_items', ['material_reservation_id' => $reservation->id, 'quantity_issued' => '5.000']);
        $this->assertDatabaseHas('material_issue_slip_items', ['material_reservation_id' => null, 'quantity_issued' => '10.000']);
    }
}
