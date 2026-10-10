<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MaintenanceLookupSafetyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_technician_can_use_narrow_lookups_without_inventory_or_mold_access(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tech = User::factory()->withRole('maintenance_tech')->create(['is_active' => true]);
        $item = Item::factory()->create(['item_type' => 'spare_part', 'name' => 'Audit lookup spare']);
        $other = Item::factory()->create(['item_type' => 'raw_material', 'name' => 'Audit lookup raw']);
        $location = WarehouseLocation::factory()->create();
        StockLevel::create(['item_id' => $item->id, 'location_id' => $location->id,
            'quantity' => '10.000', 'reserved_quantity' => '2.000', 'weighted_avg_cost' => '5.0000']);
        $this->actingAs($tech)->getJson('/api/v1/maintenance/spare-part-options?search=Audit%20lookup')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $item->hash_id);
        $this->getJson('/api/v1/maintenance/spare-part-stock?item_id='.$item->hash_id)
            ->assertOk()->assertJsonPath('data.0.available', '8.000');
        $this->getJson('/api/v1/maintenance/spare-part-stock?item_id='.$other->hash_id)->assertStatus(422);
        $this->getJson('/api/v1/maintenance/target-options?type=mold')->assertOk();
        $this->getJson('/api/v1/inventory/items')->assertForbidden();
        $this->getJson('/api/v1/mrp/molds')->assertForbidden();
    }
}
