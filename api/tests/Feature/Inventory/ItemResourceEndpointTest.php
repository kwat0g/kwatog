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
use Tests\TestCase;

class ItemResourceEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouseStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->warehouseStaff = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
        ]);
    }

    public function test_item_list_endpoint_returns_aggregated_quantities_correctly(): void
    {
        // Create an item with a specific code
        $item = Item::factory()->create([
            'code' => 'IT-T-' . substr(uniqid(), -5),
            'reorder_point' => '50.000',
            'safety_stock' => '10.000',
        ]);

        // Create stock level with on_hand and reserved quantities
        $location = WarehouseLocation::factory()->create();
        StockLevel::create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '120.000',
            'reserved_quantity' => '20.000',
            'weighted_avg_cost' => '15.5000',
            'lock_version' => 0,
        ]);

        // Test GET /api/v1/inventory/items
        $response = $this->actingAs($this->warehouseStaff)
            ->getJson('/api/v1/inventory/items')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'code',
                        'name',
                        'on_hand_quantity',
                        'reserved_quantity',
                        'available_quantity',
                        'stock_status',
                    ],
                ],
            ]);

        // Verify the specific item's quantities
        $itemData = collect($response->json('data'))->firstWhere('code', $item->code);
        $this->assertNotNull($itemData, 'Item not found in list response');
        $this->assertIsString($itemData['id'], 'Item id should be a string (HashID, not integer)');
        $this->assertSame('120.000', $itemData['on_hand_quantity']);
        $this->assertSame('20.000', $itemData['reserved_quantity']);
        $this->assertSame('100.000', $itemData['available_quantity']);
        $this->assertSame('ok', $itemData['stock_status']);
    }

    public function test_item_show_endpoint_returns_quantities_correctly(): void
    {
        // Create an item with stock
        $item = Item::factory()->create([
            'code' => 'IT-T-' . substr(uniqid(), -5),
            'reorder_point' => '50.000',
            'safety_stock' => '10.000',
        ]);

        $location = WarehouseLocation::factory()->create();
        StockLevel::create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '120.000',
            'reserved_quantity' => '20.000',
            'weighted_avg_cost' => '15.5000',
            'lock_version' => 0,
        ]);

        // Test GET /api/v1/inventory/items/{hash_id}
        $response = $this->actingAs($this->warehouseStaff)
            ->getJson("/api/v1/inventory/items/{$item->hash_id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'code',
                    'name',
                    'on_hand_quantity',
                    'reserved_quantity',
                    'available_quantity',
                    'stock_status',
                ],
            ]);

        // show() loads the same stock aggregates as list().
        $data = $response->json('data');
        $this->assertIsString($data['id'], 'Item id should be a string (HashID)');
        $this->assertSame($item->hash_id, $data['id']);
        $this->assertSame('120.000', $data['on_hand_quantity']);
        $this->assertSame('20.000', $data['reserved_quantity']);
        $this->assertSame('100.000', $data['available_quantity']);
    }

    public function test_item_with_no_stock_lists_zero_quantities(): void
    {
        // Create an item with no stock
        $item = Item::factory()->create([
            'code' => 'IT-T-' . substr(uniqid(), -5),
            'reorder_point' => '50.000',
            'safety_stock' => '10.000',
        ]);

        // Test GET /api/v1/inventory/items
        $response = $this->actingAs($this->warehouseStaff)
            ->getJson('/api/v1/inventory/items')
            ->assertOk();

        // Verify the item with no stock
        // No stock rows: SUM is NULL, the resource normalizes to 0.000.
        $itemData = collect($response->json('data'))->firstWhere('code', $item->code);
        $this->assertNotNull($itemData);
        $this->assertSame('0.000', $itemData['on_hand_quantity']);
        $this->assertSame('0.000', $itemData['reserved_quantity']);
        $this->assertSame('0.000', $itemData['available_quantity']);
        $this->assertSame('critical', $itemData['stock_status']);
    }
}
