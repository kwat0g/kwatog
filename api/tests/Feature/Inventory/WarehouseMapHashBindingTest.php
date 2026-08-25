<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WarehouseMapHashBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_bin_detail_accepts_the_hash_id_returned_by_the_map(): void
    {
        $location = WarehouseLocation::factory()->create();
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/inventory/warehouse-map/bins/{$location->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.location.id', $location->hash_id)
            ->assertJsonPath('data.location.code', $location->code);
    }

    public function test_bin_detail_reads_authoritative_stock_levels_not_legacy_projection(): void
    {
        $location = WarehouseLocation::factory()->create();
        $item = Item::factory()->create(['is_active' => true]);
        StockLevel::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '12.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '8.0000',
        ]);
        DB::table('warehouse_locations')->where('id', $location->id)->update([
            'current_item_id' => null,
            'current_quantity' => '999.000',
            'current_lot_number' => 'STALE-LOT',
        ]);

        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/inventory/warehouse-map/bins/{$location->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.location.current_item.id', $item->hash_id)
            ->assertJsonPath('data.location.current_quantity', '12.000')
            ->assertJsonPath('data.stock_levels.0.quantity', '12.000')
            ->assertJsonPath('data.location.current_lot_number', null);
    }
}
