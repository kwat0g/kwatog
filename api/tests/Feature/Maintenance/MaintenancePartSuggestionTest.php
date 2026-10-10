<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Models\SparePartUsage;
use App\Modules\Maintenance\Services\SparePartUsageService;
use App\Modules\MRP\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MaintenancePartSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_target_history_ranks_distinct_orders_and_reserved_stock_is_not_usable(): void
    {
        Event::fake();
        $machine = Machine::factory()->create();
        $actor = User::factory()->create();
        $order = $this->order($machine, $actor, 'in_progress');
        $historical = $this->order($machine, $actor, 'completed');
        $ordinary = Item::factory()->create(['code' => 'AAA', 'item_type' => 'spare_part']);
        $usual = Item::factory()->create(['code' => 'ZZZ', 'item_type' => 'spare_part']);
        foreach ([1, 2] as $unused) {
            SparePartUsage::create(['work_order_id' => $historical->id, 'item_id' => $usual->id,
                'quantity' => '1', 'unit_cost' => '1', 'total_cost' => '1', 'created_at' => now()]);
        }
        StockLevel::factory()->create(['item_id' => $usual->id, 'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity' => '5', 'reserved_quantity' => '5']);
        $rows = app(SparePartUsageService::class)->options($order, '');
        $this->assertSame($usual->hash_id, $rows[0]['id']);
        $this->assertSame(1, $rows[0]['history_count']);
        $this->assertSame('no_usable_stock', $rows[0]['stock_status']);
        $this->assertSame([], $rows[0]['stock']);
        $this->assertSame($ordinary->hash_id, app(SparePartUsageService::class)->options(null, '')[0]['id']);
        $this->assertArrayNotHasKey('unit_cost', $rows[0]);
    }

    public function test_expired_and_quality_held_stock_are_excluded(): void
    {
        Event::fake();
        $item = Item::factory()->create(['item_type' => 'spare_part']);
        $location = WarehouseLocation::factory()->create();
        StockLevel::factory()->create(['item_id' => $item->id, 'location_id' => $location->id,
            'quantity' => '5', 'reserved_quantity' => '0']);
        \App\Modules\Inventory\Models\StockMovement::create(['item_id' => $item->id,
            'to_location_id' => $location->id, 'movement_type' => 'grn_receipt', 'quantity' => '5',
            'unit_cost' => '1', 'total_cost' => '5', 'lot_number' => 'EXPIRED', 'expiry_date' => now()->subDay()]);
        $this->assertSame('no_usable_stock', app(SparePartUsageService::class)->options(null, '')[0]['stock_status']);
        \App\Modules\Inventory\Models\StockMovement::query()->update(['expiry_date' => now()->addDay()]);
        $available = app(SparePartUsageService::class)->options(null, '')[0];
        $this->assertSame('EXPIRED', $available['stock'][0]['lots'][0]['lot_number']);
        $this->assertSame('5.000', $available['stock'][0]['available']);
        $this->mock(\App\Modules\Quality\Services\QualityStockHoldService::class)
            ->shouldReceive('heldQuantity')->andReturn('5.000');
        $this->assertSame([], app(SparePartUsageService::class)->options(null, '')[0]['stock']);
    }

    public function test_options_require_maintenance_execution_permission(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        $role = \App\Modules\Auth\Models\Role::query()->where('slug', 'maintenance_tech')->firstOrFail();
        $actor = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($actor)->getJson('/api/v1/maintenance/spare-part-options')->assertOk();
        $actor->update(['role_id' => \App\Modules\Auth\Models\Role::query()->where('slug', 'employee')->value('id')]);
        $this->actingAs($actor->fresh())->getJson('/api/v1/maintenance/spare-part-options')->assertForbidden();
    }

    private function order(Machine $machine, User $actor, string $status): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::create(['mwo_number' => 'PART-'.uniqid(), 'maintainable_type' => 'machine',
            'maintainable_id' => $machine->id, 'type' => 'corrective', 'priority' => 'medium',
            'description' => 'Part suggestion test', 'status' => $status, 'created_by' => $actor->id,
            'completed_at' => $status === 'completed' ? now() : null]);
    }
}
