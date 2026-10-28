<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class InventoryStockSignalParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Cache::flush();
    }

    public function test_stock_badges_match_active_inventory_lists_using_usable_stock(): void
    {
        $warehouse = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
        ]);
        $low = Item::factory()->create(['reorder_point' => '5.000', 'safety_stock' => '1.000']);
        $out = Item::factory()->create(['reorder_point' => '0.000', 'safety_stock' => '0.000']);
        $inactive = Item::factory()->create(['is_active' => false, 'reorder_point' => '5.000']);
        $archived = Item::factory()->create(['reorder_point' => '5.000']);
        $archived->delete();
        $location = WarehouseLocation::factory()->create();

        foreach ([[$low, '3.000', '0.000'], [$out, '4.000', '4.000'], [$inactive, '0.000', '0.000'], [$archived, '0.000', '0.000']] as [$item, $quantity, $reserved]) {
            StockLevel::factory()->create([
                'item_id' => $item->id,
                'location_id' => $location->id,
                'quantity' => $quantity,
                'reserved_quantity' => $reserved,
            ]);
        }

        $badges = $this->actingAs($warehouse, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');
        $this->assertSame('danger', $badges['out_of_stock']['severity']);

        foreach (['low_stock' => $low, 'out_of_stock' => $out] as $signal => $expectedItem) {
            $list = $this->actingAs($warehouse, 'sanctum')
                ->getJson("/api/v1/inventory/items?signal={$signal}&trashed=with&per_page=100")
                ->assertOk();

            $this->assertSame($badges[$signal]['count'], $list->json('meta.total'), $signal.': '.json_encode($list->json()));
            $this->assertSame([$expectedItem->code], collect($list->json('data'))->pluck('code')->all());
        }
    }

    public function test_item_list_rejects_unknown_stock_signal(): void
    {
        $warehouse = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
        ]);

        $this->actingAs($warehouse, 'sanctum')
            ->getJson('/api/v1/inventory/items?signal=everything')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('signal');
    }
}
