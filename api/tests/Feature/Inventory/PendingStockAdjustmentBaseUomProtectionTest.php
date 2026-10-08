<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockAdjustmentReason;
use App\Modules\Inventory\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PendingStockAdjustmentBaseUomProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_adjustment_locks_its_base_uom_until_checker_posts_it(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        Uom::query()->firstOrCreate(['code' => 'PCS'], ['name' => 'Pieces']);
        Uom::query()->firstOrCreate(['code' => 'KG'], ['name' => 'Kilogram']);

        $warehouse = $this->userWith('warehouse_staff', ['inventory.adjust', 'inventory.view']);
        $admin = $this->userWith('system_admin', ['inventory.items.manage']);
        $finance = $this->userWith('finance_officer', ['inventory.adjust.approve']);
        $item = Item::factory()->create(['unit_of_measure' => 'PCS', 'is_active' => true]);
        $location = WarehouseLocation::factory()->create(['is_active' => true]);
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 1);

        $created = $this->actingAs($warehouse)
            ->postJson('/api/v1/inventory/stock-adjustments', [
                'item_id' => $item->hash_id,
                'location_id' => $location->hash_id,
                'direction' => 'in',
                'quantity' => '1',
                'unit_cost' => '50',
                'reason' => 'Base unit cannot change before Finance approval.',
                'reason_code' => StockAdjustmentReason::FoundStock->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', StockAdjustmentStatus::Pending->value)
            ->assertJsonPath('data.stock_movement', null);

        $adjustment = StockAdjustment::query()->where('item_id', $item->id)->firstOrFail();
        $adjustmentsBefore = DB::table('stock_adjustments')->count();
        $movementsBefore = DB::table('stock_movements')->count();
        $auditsBeforeEdit = DB::table('audit_logs')->count();

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/items/{$item->hash_id}", ['unit_of_measure' => 'KG'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change the base unit while a stock adjustment is pending.');

        $this->assertSame('PCS', $item->fresh()->unit_of_measure);
        $this->assertSame(StockAdjustmentStatus::Pending, $adjustment->fresh()->status);
        $this->assertNull($adjustment->fresh()->stock_movement_id);
        $this->assertSame($adjustmentsBefore, DB::table('stock_adjustments')->count());
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame($auditsBeforeEdit, DB::table('audit_logs')->count());
        $this->assertSame(0, StockLevel::query()->where('item_id', $item->id)->count());

        $this->actingAs($finance)
            ->patchJson("/api/v1/inventory/stock-adjustments/{$adjustment->hash_id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', StockAdjustmentStatus::Approved->value)
            ->assertJsonPath('data.stock_movement.quantity', '1.000');

        $this->assertSame('PCS', $item->fresh()->unit_of_measure);
        $this->assertSame(1, DB::table('stock_movements')->where('reference_id', $adjustment->id)->count());
        $this->assertSame('1.000', (string) StockLevel::query()->where('item_id', $item->id)->value('quantity'));
        $this->assertSame($finance->id, $adjustment->fresh()->approved_by);
    }

    /** @param list<string> $permissions */
    private function userWith(string $roleSlug, array $permissions): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $role->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('slug', $permissions)->pluck('id')->all(),
        );

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
