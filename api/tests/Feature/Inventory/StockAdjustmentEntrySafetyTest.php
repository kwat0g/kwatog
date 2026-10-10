<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StockAdjustmentEntrySafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Item $item;
    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, ChartOfAccountsSeeder::class]);
        Event::fake([StockMovementCompleted::class]);
        $this->actor = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $this->item = Item::factory()->create(['is_active' => true, 'unit_of_measure' => 'pcs']);
        $this->location = WarehouseLocation::factory()->create(['is_active' => true, 'is_blocked' => false]);
        StockLevel::create(['item_id' => $this->item->id, 'location_id' => $this->location->id,
            'quantity' => '20', 'reserved_quantity' => '2', 'weighted_avg_cost' => '15.0000', 'lock_version' => 0]);
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 0);
        $this->actingAs($this->actor);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['item_id' => $this->item->hash_id, 'location_id' => $this->location->hash_id,
            'direction' => 'out', 'quantity' => '5', 'reason_code' => 'system_correction',
            'reason' => 'Warehouse adjustment test'], $changes);
    }

    public function test_retry_replays_one_adjustment_even_after_available_stock_has_changed(): void
    {
        $first = $this->withHeader('Idempotency-Key', 'adjustment-retry')->postJson('/api/v1/inventory/stock-adjustments', $this->payload())->assertCreated();
        $again = $this->withHeader('Idempotency-Key', 'adjustment-retry')->postJson('/api/v1/inventory/stock-adjustments', $this->payload())->assertCreated();
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertDatabaseCount('stock_adjustments', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_levels', ['item_id' => $this->item->id, 'location_id' => $this->location->id, 'quantity' => '15.000']);
    }

    public function test_same_key_cannot_create_a_different_adjustment(): void
    {
        $this->withHeader('Idempotency-Key', 'adjustment-conflict')->postJson('/api/v1/inventory/stock-adjustments', $this->payload())->assertCreated();
        $this->withHeader('Idempotency-Key', 'adjustment-conflict')->postJson('/api/v1/inventory/stock-adjustments', $this->payload(['quantity' => '6']))->assertUnprocessable();
        $this->assertDatabaseCount('stock_adjustments', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_pending_retry_preserves_one_request_without_changing_stock(): void
    {
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 1);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withHeader('Idempotency-Key', 'adjustment-pending')->postJson('/api/v1/inventory/stock-adjustments', $this->payload())
                ->assertCreated()->assertJsonPath('data.status', 'pending');
        }
        $this->assertDatabaseCount('stock_adjustments', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('stock_levels', ['item_id' => $this->item->id, 'quantity' => '20.000']);
    }

    public function test_inbound_cost_required_and_inactive_items_rejected_without_writes(): void
    {
        $this->postJson('/api/v1/inventory/stock-adjustments', $this->payload(['direction' => 'in']))
            ->assertUnprocessable()->assertJsonValidationErrors('unit_cost');
        $this->item->update(['is_active' => false]);
        $this->postJson('/api/v1/inventory/stock-adjustments', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_blocked_receiving_location_cannot_enter_approval_queue(): void
    {
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 1);
        $this->location->update(['is_blocked' => true]);
        $this->postJson('/api/v1/inventory/stock-adjustments', $this->payload(['direction' => 'in', 'unit_cost' => '15.0000']))->assertUnprocessable();
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_outbound_cost_is_authoritative_and_invalid_key_is_rejected(): void
    {
        $this->withHeader('Idempotency-Key', 'invalid key')->postJson('/api/v1/inventory/stock-adjustments', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->withHeader('Idempotency-Key', 'valid-key')->postJson('/api/v1/inventory/stock-adjustments', $this->payload(['unit_cost' => '0.0000']))
            ->assertCreated()->assertJsonPath('data.unit_cost', '15.0000');
        $this->assertDatabaseCount('stock_adjustments', 1);
    }
}
