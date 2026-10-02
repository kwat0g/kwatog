<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockCountSessionStatus;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCountEmptyScopeTest extends TestCase
{
    use RefreshDatabase;

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
