<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockCountSessionStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCountEmptyScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_restarting_an_active_count_session_returns_a_conflict(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $warehouse = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
            'is_active' => true,
        ]);
        $location = WarehouseLocation::factory()->create(['is_active' => true]);
        $item = Item::factory()->create(['is_active' => true]);
        StockLevel::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '10.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '5.0000',
            'lock_version' => 0,
        ]);

        $sessionId = $this->actingAs($warehouse)
            ->postJson('/api/v1/inventory/stock-counts', [
                'title' => 'Stale start conflict',
                'scope' => 'zone',
                'zone_id' => $location->zone->hash_id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($warehouse)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/start")
            ->assertOk()
            ->assertJsonPath('data.status', StockCountSessionStatus::InProgress->value);

        $lineId = $this->actingAs($warehouse)
            ->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk()
            ->json('data.items.0.id');

        $this->actingAs($warehouse)
            ->postJson("/api/v1/inventory/stock-counts/items/{$lineId}/approve")
            ->assertConflict()
            ->assertJsonPath('code', 'stock_count_state_conflict')
            ->assertJsonPath('errors.status.0', 'This count line is no longer awaiting variance approval. Refresh and review its current status.');

        $this->actingAs($warehouse)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/start")
            ->assertConflict()
            ->assertJsonPath('code', 'stock_count_state_conflict')
            ->assertJsonPath('errors.status.0', 'This stock-count session is no longer a draft. Refresh and review its current status.');

        $this->actingAs($warehouse)
            ->deleteJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertNoContent();
        $this->actingAs($warehouse)
            ->postJson("/api/v1/inventory/stock-counts/items/{$lineId}/count", ['counted_quantity' => '11.000'])
            ->assertConflict()
            ->assertJsonPath('code', 'stock_count_state_conflict');
        $this->actingAs($warehouse)
            ->postJson("/api/v1/inventory/stock-counts/items/{$lineId}/approve")
            ->assertConflict()
            ->assertJsonPath('code', 'stock_count_state_conflict');
        $this->actingAs($warehouse)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/complete")
            ->assertConflict()
            ->assertJsonPath('code', 'stock_count_state_conflict');
        $this->actingAs($warehouse)
            ->deleteJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertConflict()
            ->assertJsonPath('code', 'stock_count_state_conflict');
    }

    public function test_empty_draft_cannot_start_and_can_be_cancelled(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $warehouse = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
            'is_active' => true,
        ]);
        $location = WarehouseLocation::factory()->create(['is_active' => true]);

        $sessionId = $this->actingAs($warehouse)
            ->postJson('/api/v1/inventory/stock-counts', [
                'title' => 'Empty stock-count scope',
                'scope' => 'zone',
                'zone_id' => $location->zone->hash_id,
            ])
            ->assertCreated()
            ->json('data.id');
        $session = StockCountSession::query()->where('title', 'Empty stock-count scope')->firstOrFail();
        $before = $this->actingAs($warehouse)
            ->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.status', StockCountSessionStatus::Draft->value)
            ->assertJsonPath('data.items', []);
        $auditBefore = AuditLog::query()
            ->where('model_type', $session->getMorphClass())
            ->where('model_id', $session->id)
            ->count();

        $this->actingAs($warehouse)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/start")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This scope has no stock items to count. Choose a scope with stock or cancel this session.');

        $after = $this->actingAs($warehouse)
            ->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.status', StockCountSessionStatus::Draft->value)
            ->assertJsonPath('data.frozen_at', null)
            ->assertJsonPath('data.items', []);
        $this->assertSame($before->json('data'), $after->json('data'));
        $this->assertSame($auditBefore, AuditLog::query()
            ->where('model_type', $session->getMorphClass())
            ->where('model_id', $session->id)
            ->count());

        $this->actingAs($warehouse)
            ->deleteJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertNoContent();
        $this->actingAs($warehouse)
            ->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.status', StockCountSessionStatus::Cancelled->value);
    }
}
