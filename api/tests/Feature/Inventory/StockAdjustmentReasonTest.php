<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockAdjustmentReason;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\WarehouseService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * OGAMI-012 — stock-adjustment reason codes + value-threshold approval gate.
 */
class StockAdjustmentReasonTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private StockAdjustmentService $svc;

    private Item $item;

    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([StockMovementCompleted::class]);
        $this->seed(ChartOfAccountsSeeder::class);

        $role = Role::firstOrCreate(['slug' => 'system_admin'], ['name' => 'System Admin']);
        $this->admin = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->svc = app(StockAdjustmentService::class);
        $this->item = Item::factory()->create(['is_active' => true]);
        $this->location = WarehouseLocation::factory()->create();

        // Seed some on-hand stock so adjustOut has a WAC to cost against.
        StockLevel::query()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => '1000',
            'reserved_quantity' => '0',
            'weighted_avg_cost' => '10.0000',
            'lock_version' => 0,
        ]);
    }

    public function test_create_stores_reason_code_and_applies_when_gate_disabled(): void
    {
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 0);

        $adj = $this->svc->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'in',
            qty: '10',
            unitCost: '10.00',
            reason: 'Found extra stock during count',
            by: $this->admin,
            reasonCode: StockAdjustmentReason::FoundStock,
        );

        $this->assertSame('approved', $adj->getRawOriginal('status'));
        $this->assertSame(StockAdjustmentReason::FoundStock, $adj->reason_code);
        $this->assertNotNull($adj->stock_movement_id);
        $this->assertSame('100.00', (string) $adj->value);
        $this->assertSame(1, AuditLog::query()
            ->where('model_type', $adj->getMorphClass())
            ->where('model_id', $adj->id)
            ->count());
    }

    public function test_above_threshold_adjustment_is_held_pending_with_no_movement(): void
    {
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 500);
        $maker = $this->warehouseUser();

        // 100 * 10 = ₱1000 > ₱500 → must be pending.
        $adj = $this->svc->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'out',
            qty: '100',
            unitCost: null, // out uses current WAC (10.00)
            reason: 'Damaged goods written off',
            by: $maker,
            reasonCode: StockAdjustmentReason::Damage,
        );

        $this->assertSame('pending', $adj->getRawOriginal('status'));
        $this->assertNull($adj->stock_movement_id);

        // Approving posts the movement and flips to approved.
        $approved = $this->svc->approve($adj->fresh(), $this->admin);
        $this->assertSame('approved', $approved->getRawOriginal('status'));
        $this->assertNotNull($approved->stock_movement_id);
    }

    public function test_pending_adjustment_rejects_inactive_location_without_writing(): void
    {
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 500);
        $this->location->update(['is_active' => false]);
        $before = StockAdjustment::query()->count();

        try {
            $this->svc->create(
                itemId: $this->item->id,
                locationId: $this->location->id,
                direction: 'out',
                qty: '100',
                unitCost: null,
                reason: 'Pending adjustment must not target inactive location',
                by: $this->admin,
                reasonCode: StockAdjustmentReason::Damage,
            );
            $this->fail('An inactive location must not receive a pending adjustment.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('missing or inactive', $exception->getMessage());
        }

        $this->assertSame($before, StockAdjustment::query()->count());
    }

    public function test_location_cannot_be_deactivated_while_adjustment_is_pending(): void
    {
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 500);
        StockLevel::query()->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->update(['quantity' => '0.000', 'reserved_quantity' => '0.000']);

        $pending = $this->svc->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'in',
            qty: '100',
            unitCost: '10.00',
            reason: 'Pending adjustment protects its active location',
            by: $this->admin,
            reasonCode: StockAdjustmentReason::FoundStock,
        );

        try {
            app(WarehouseService::class)->updateLocation($this->location, ['is_active' => false]);
            $this->fail('A location with a pending adjustment must stay active.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('pending stock adjustments', $exception->getMessage());
        }

        $this->assertTrue($this->location->fresh()->is_active);
        $this->assertSame('pending', $pending->fresh()->getRawOriginal('status'));
        $this->assertNull($pending->fresh()->stock_movement_id);
    }

    public function test_pending_adjustment_zone_cannot_move_to_inactive_warehouse_through_api(): void
    {
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 500);
        StockLevel::query()->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->update(['quantity' => '0.000', 'reserved_quantity' => '0.000']);

        $pending = $this->svc->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'in',
            qty: '100',
            unitCost: '10.00',
            reason: 'Pending adjustment protects its active warehouse',
            by: $this->warehouseUser(),
            reasonCode: StockAdjustmentReason::FoundStock,
        );
        $zone = WarehouseZone::query()->findOrFail($this->location->zone_id);
        $inactiveWarehouse = Warehouse::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/inventory/zones/'.$zone->hash_id, [
                'warehouse_id' => $inactiveWarehouse->hash_id,
                'name' => $zone->name,
                'code' => $zone->code,
                'zone_type' => $zone->getRawOriginal('zone_type'),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot move a zone into an inactive warehouse.');

        $this->assertSame((int) $zone->warehouse_id, (int) $zone->fresh()->warehouse_id);
        $this->assertSame('pending', $pending->fresh()->getRawOriginal('status'));
        $this->assertNull($pending->fresh()->stock_movement_id);
        $this->assertSame('0.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));
    }

    public function test_pending_out_adjustment_uses_wac_at_approval_time(): void
    {
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 500);
        $maker = $this->warehouseUser();

        $pending = $this->svc->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'out',
            qty: '100',
            unitCost: null,
            reason: 'Write off damaged stock after review',
            by: $maker,
            reasonCode: StockAdjustmentReason::Damage,
        );

        // Change the ledger WAC while the approval is pending through a real
        // inbound movement, not by mutating the adjustment snapshot.
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 0);
        $this->svc->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'in',
            qty: '100',
            unitCost: '20.00',
            reason: 'Receipt landed before approval',
            by: $this->admin,
            reasonCode: StockAdjustmentReason::SystemCorrection,
        );
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 500);

        $approved = $this->svc->approve($pending->fresh(), $this->admin);
        $movement = $approved->stockMovement()->firstOrFail();

        $this->assertSame('10.9091', (string) $movement->unit_cost);
        $this->assertSame('1090.91', (string) $approved->value);
        $this->assertSame((string) $movement->unit_cost, (string) $approved->unit_cost);
    }

    public function test_below_threshold_adjustment_applies_immediately(): void
    {
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 500);

        // 10 * 10 = ₱100 <= ₱500 → applied immediately.
        $adj = $this->svc->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'in',
            qty: '10',
            unitCost: '10.00',
            reason: 'System correction after audit',
            by: $this->admin,
            reasonCode: StockAdjustmentReason::SystemCorrection,
        );

        $this->assertSame('approved', $adj->getRawOriginal('status'));
        $this->assertNotNull($adj->stock_movement_id);
    }

    public function test_invalid_reason_code_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->svc->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'in',
            qty: '5',
            unitCost: '10.00',
            reason: 'Bad reason code path',
            by: $this->admin,
            reasonCode: 'not_a_real_code',
        );
    }

    public function test_approve_requires_permission(): void
    {
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 500);

        $adj = $this->svc->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'out',
            qty: '100',
            unitCost: null,
            reason: 'Theft reported to security',
            by: $this->admin,
            reasonCode: StockAdjustmentReason::Theft,
        );
        $this->assertSame('pending', $adj->getRawOriginal('status'));

        // A user lacking inventory.adjust.approve cannot approve.
        $role = Role::firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);
        $staff = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->expectException(RuntimeException::class);
        $this->svc->approve($adj->fresh(), $staff);
    }

    public function test_ordinary_adjustment_uses_the_gated_create_boundary(): void
    {
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 0);

        $adj = $this->svc->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'in',
            qty: '5',
            unitCost: '10.00',
            reason: 'Ordinary adjustment through the gated boundary',
            by: $this->admin,
        );

        $this->assertSame('approved', $adj->getRawOriginal('status'));
        $this->assertNotNull($adj->stock_movement_id);
    }

    public function test_generic_legacy_immediate_adjustment_methods_are_gone(): void
    {
        $this->assertFalse(method_exists(StockAdjustmentService::class, 'adjustIn'));
        $this->assertFalse(method_exists(StockAdjustmentService::class, 'adjustOut'));
        $this->assertTrue(method_exists(StockAdjustmentService::class, 'reconcileStockCountItem'));
    }

    private function warehouseUser(): User
    {
        $role = Role::firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
