<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockAdjustmentReason;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Common\Services\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OGAMI-012 — stock-adjustment approval queue (list + approve API).
 *
 * The queue surfaced the pending adjustments for the checker (finance_officer).
 * Covers: pending rows are listed, approved rows are excluded when filtered,
 * the approve endpoint posts the movement, and the maker cannot self-approve.
 */
class StockAdjustmentQueueTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;
    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->item     = Item::factory()->create(['is_active' => true]);
        $this->location = WarehouseLocation::factory()->create();
        StockLevel::query()->create([
            'item_id'           => $this->item->id,
            'location_id'       => $this->location->id,
            'quantity'          => '1000',
            'reserved_quantity' => '0',
            'weighted_avg_cost' => '10.0000',
            'lock_version'      => 0,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function userWith(string $roleSlug, array $permissions): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug]);
        $role->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('slug', $permissions)->pluck('id')->all(),
        );
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function pendingAdjustment(User $by): StockAdjustment
    {
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 1);
        return app(\App\Modules\Inventory\Services\StockAdjustmentService::class)->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'out',
            qty: '5',
            unitCost: '10.0000',
            reason: 'Queue test adjustment ' . uniqid(),
            by: $by,
            reasonCode: StockAdjustmentReason::Damage,
        );
    }

    // ─── Tests ───────────────────────────────────────────────────────────────

    public function test_index_lists_pending_adjustments_with_status_filter(): void
    {
        $warehouse = $this->userWith('warehouse_staff', ['inventory.adjust', 'inventory.view']);
        $pending   = $this->pendingAdjustment($warehouse);

        $response = $this->actingAs($warehouse)
            ->getJson('/api/v1/inventory/stock-adjustments?status=pending')
            ->assertOk();

        $this->assertSame($pending->hash_id, $response->json('data.0.id'));
        $this->assertSame('pending', $response->json('data.0.status'));
    }

    public function test_index_hides_approved_rows_when_filtered_pending(): void
    {
        $warehouse = $this->userWith('warehouse_staff', ['inventory.adjust', 'inventory.view']);
        $pending   = $this->pendingAdjustment($warehouse);
        $pending->forceFill(['status' => 'approved'])->save();

        $this->actingAs($warehouse)
            ->getJson('/api/v1/inventory/stock-adjustments?status=pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_checker_can_approve_pending_adjustment_via_api(): void
    {
        $warehouse = $this->userWith('warehouse_staff', ['inventory.adjust', 'inventory.view']);
        $finance   = $this->userWith('finance_officer', ['inventory.adjust.approve']);
        $pending   = $this->pendingAdjustment($warehouse);

        $this->actingAs($finance)
            ->patchJson("/api/v1/inventory/stock-adjustments/{$pending->hash_id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('stock_adjustments', ['id' => $pending->id, 'status' => 'approved']);
        $this->assertNotNull($pending->fresh()->stock_movement_id, 'approving must post the stock movement.');
    }

    public function test_maker_cannot_approve_own_adjustment(): void
    {
        $warehouse = $this->userWith('warehouse_staff', ['inventory.adjust', 'inventory.view']);
        $pending   = $this->pendingAdjustment($warehouse);

        $this->actingAs($warehouse)
            ->patchJson("/api/v1/inventory/stock-adjustments/{$pending->hash_id}/approve")
            ->assertStatus(403);
    }
}
