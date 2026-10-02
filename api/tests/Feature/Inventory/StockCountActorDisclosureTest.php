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

class StockCountActorDisclosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_count_responses_expose_only_actor_id_and_name(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $roleId = Role::query()->where('slug', 'warehouse_staff')->value('id');
        $maker = User::factory()->create(['role_id' => $roleId, 'is_active' => true]);
        $checker = User::factory()->create(['role_id' => $roleId, 'is_active' => true]);
        $item = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create(['is_active' => true]);
        StockLevel::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '1.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '10.0000',
            'lock_version' => 0,
        ]);

        $sessionId = $this->actingAs($maker)
            ->postJson('/api/v1/inventory/stock-counts', [
                'title' => 'Actor disclosure',
                'scope' => 'zone',
                'zone_id' => $location->zone->hash_id,
            ])
            ->assertCreated()
            ->json('data.id');
        $this->actingAs($maker)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/start")
            ->assertOk();
        $lineId = $this->actingAs($maker)
            ->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk()
            ->json('data.items.0.id');
        $this->actingAs($maker)
            ->postJson("/api/v1/inventory/stock-counts/items/{$lineId}/count", ['counted_quantity' => '2.000'])
            ->assertOk();
        $this->actingAs($checker)
            ->postJson("/api/v1/inventory/stock-counts/items/{$lineId}/approve")
            ->assertOk()
            ->assertJsonPath('data.verified_by.id', $checker->hash_id);

        $detail = $this->actingAs($maker)
            ->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk();
        $this->assertSame(
            ['id' => $maker->hash_id, 'name' => $maker->name],
            $detail->json('data.created_by'),
        );
        $this->assertSame(
            ['id' => $maker->hash_id, 'name' => $maker->name],
            $detail->json('data.items.0.counted_by'),
        );
        $this->assertSame(
            ['id' => $checker->hash_id, 'name' => $checker->name],
            $detail->json('data.items.0.verified_by'),
        );
    }
}
