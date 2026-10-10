<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockCountItemStatus;
use App\Modules\Inventory\Enums\StockCountSessionStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCountVarianceSoDTest extends TestCase
{
    use RefreshDatabase;

    public function test_variance_at_the_tolerance_does_not_accept_an_unneeded_approval(): void
    {
        ['maker' => $maker, 'checker' => $checker, 'session_id' => $sessionId, 'line_id' => $lineId] = $this->createHighVarianceCount();
        app(SettingsService::class)->set('inventory.stock_count.variance_tolerance_pct', 100);

        $before = $this->actingAs($maker)
            ->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk()
            ->json('data');
        $auditCount = AuditLog::query()->count();
        $movementCount = StockMovement::query()->count();

        $this->actingAs($checker)
            ->postJson("/api/v1/inventory/stock-counts/items/{$lineId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This variance is at or below the approval tolerance.');

        $after = $this->actingAs($maker)
            ->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk()
            ->json('data');
        $this->assertSame($before, $after);
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertSame($movementCount, StockMovement::query()->count());
    }

    public function test_variance_checker_cannot_complete_the_same_stock_count(): void
    {
        ['maker' => $maker, 'checker' => $checker, 'finisher' => $finisher, 'item' => $item, 'session_id' => $sessionId, 'line_id' => $lineId] = $this->createHighVarianceCount();

        $this->actingAs($checker)
            ->postJson("/api/v1/inventory/stock-counts/items/{$lineId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', StockCountItemStatus::Verified->value)
            ->assertJsonPath('data.verified_by.id', $checker->hash_id);

        $movementsBefore = StockMovement::query()->count();
        $this->actingAs($checker)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/complete")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A different user must complete a stock count after recording or approving a line.');

        $this->actingAs($maker)
            ->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.status', StockCountSessionStatus::InProgress->value)
            ->assertJsonPath('data.items.0.status', StockCountItemStatus::Verified->value)
            ->assertJsonPath('data.items.0.verified_by.id', $checker->hash_id);
        $this->assertSame('1.000', (string) StockLevel::query()->where('item_id', $item->id)->value('quantity'));
        $this->assertSame($movementsBefore, StockMovement::query()->count());

        $this->actingAs($finisher)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', StockCountSessionStatus::Completed->value)
            ->assertJsonPath('data.approved_by.id', $finisher->hash_id)
            ->assertJsonPath('data.items.0.status', StockCountItemStatus::Adjusted->value);
        $this->assertSame('2.000', (string) StockLevel::query()->where('item_id', $item->id)->value('quantity'));
        $this->assertSame($movementsBefore + 1, StockMovement::query()->count());
    }

    public function test_recounting_after_variance_approval_clears_approval_and_requires_reapproval(): void
    {
        ['maker' => $maker, 'checker' => $checker, 'finisher' => $finisher, 'item' => $item, 'session_id' => $sessionId, 'line_id' => $lineId] = $this->createHighVarianceCount();

        $this->actingAs($checker)
            ->postJson("/api/v1/inventory/stock-counts/items/{$lineId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', StockCountItemStatus::Verified->value);

        $this->actingAs($maker)
            ->postJson("/api/v1/inventory/stock-counts/items/{$lineId}/count", ['counted_quantity' => '2.500'])
            ->assertOk()
            ->assertJsonPath('data.status', StockCountItemStatus::Counted->value);

        $this->actingAs($maker)
            ->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.verified_by', null);

        $movementsBefore = StockMovement::query()->count();
        $this->actingAs($finisher)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/complete")
            ->assertUnprocessable();
        $this->assertSame('1.000', (string) StockLevel::query()->where('item_id', $item->id)->value('quantity'));
        $this->assertSame($movementsBefore, StockMovement::query()->count());

        $this->actingAs($checker)
            ->postJson("/api/v1/inventory/stock-counts/items/{$lineId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', StockCountItemStatus::Verified->value);
        $this->actingAs($finisher)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', StockCountSessionStatus::Completed->value);
        $this->assertSame('2.500', (string) StockLevel::query()->where('item_id', $item->id)->value('quantity'));
        $this->assertSame($movementsBefore + 1, StockMovement::query()->count());
    }

    /** @return array{maker: User, checker: User, finisher: User, item: Item, session_id: string, line_id: string} */
    private function createHighVarianceCount(): array
    {
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('inventory.stock_count.variance_tolerance_pct', 0);
        $roleId = Role::query()->where('slug', 'warehouse_staff')->value('id');
        $maker = $this->warehouseUser($roleId);
        $checker = $this->warehouseUser($roleId);
        $finisher = $this->warehouseUser($roleId);
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

        $sessionId = $this->actingAs($maker)
            ->postJson('/api/v1/inventory/stock-counts', [
                'title' => 'Variance checker separation',
                'scope' => 'zone',
                'zone_id' => $location->zone->hash_id,
            ])
            ->assertCreated()
            ->json('data.id');
        $this->actingAs($maker)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/start")
            ->assertOk()
            ->assertJsonPath('data.status', StockCountSessionStatus::InProgress->value);
        $lineId = $this->actingAs($maker)
            ->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk()
            ->json('data.items.0.id');
        $this->actingAs($maker)
            ->postJson("/api/v1/inventory/stock-counts/items/{$lineId}/count", ['counted_quantity' => '2.000'])
            ->assertOk()
            ->assertJsonPath('data.status', StockCountItemStatus::Counted->value);

        return compact('maker', 'checker', 'finisher', 'item') + ['session_id' => $sessionId, 'line_id' => $lineId];
    }

    private function warehouseUser(int $roleId): User
    {
        return User::factory()->create([
            'role_id' => $roleId,
            'is_active' => true,
        ]);
    }
}
