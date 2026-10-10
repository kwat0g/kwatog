<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCountItemAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_count_entry_audits_authenticated_actor_and_before_after_values(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $warehouse = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
            'is_active' => true,
        ]);
        $item = Item::factory()->create(['is_active' => true, 'unit_of_measure' => 'kg']);
        $location = WarehouseLocation::factory()->create(['is_active' => true]);
        StockLevel::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '1.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '10.0000',
            'lock_version' => 0,
        ]);

        $sessionId = $this->actingAs($warehouse)
            ->postJson('/api/v1/inventory/stock-counts', [
                'title' => 'Stock-count audit regression',
                'scope' => 'zone',
                'zone_id' => $location->zone->hash_id,
            ])
            ->assertCreated()
            ->json('data.id');
        $this->actingAs($warehouse)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $countItem = StockCountItem::query()->where('item_id', $item->id)->firstOrFail();

        $this->actingAs($warehouse)
            ->postJson("/api/v1/inventory/stock-counts/items/{$countItem->hash_id}/count", [
                'counted_quantity' => '1.500',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'counted');

        $audit = AuditLog::query()
            ->where('model_type', $countItem->getMorphClass())
            ->where('model_id', $countItem->getKey())
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($audit, 'count edits must have an immutable audit row');
        $this->assertSame($warehouse->id, $audit->user_id);
        $this->assertSame('user', $audit->actor_type);
        $this->assertSame('pending', $audit->old_values['status']);
        $this->assertSame('counted', $audit->new_values['status']);
        $this->assertSame('1.500', (string) $audit->new_values['counted_quantity']);
        $this->assertSame($warehouse->id, $audit->new_values['counted_by']);
    }
}
