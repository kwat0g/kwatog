<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockAdjustmentReason;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Common\Services\SettingsService;
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

        $role = Role::firstOrCreate(['slug' => 'system_admin'], ['name' => 'System Admin']);
        $this->admin = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->svc      = app(StockAdjustmentService::class);
        $this->item     = Item::factory()->create(['is_active' => true]);
        $this->location = WarehouseLocation::factory()->create();

        // Seed some on-hand stock so adjustOut has a WAC to cost against.
        StockLevel::query()->create([
            'item_id'           => $this->item->id,
            'location_id'       => $this->location->id,
            'quantity'          => '1000',
            'reserved_quantity' => '0',
            'weighted_avg_cost' => '10.0000',
            'lock_version'      => 0,
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
    }

    public function test_above_threshold_adjustment_is_held_pending_with_no_movement(): void
    {
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 500);

        // 100 * 10 = ₱1000 > ₱500 → must be pending.
        $adj = $this->svc->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'out',
            qty: '100',
            unitCost: null, // out uses current WAC (10.00)
            reason: 'Damaged goods written off',
            by: $this->admin,
            reasonCode: StockAdjustmentReason::Damage,
        );

        $this->assertSame('pending', $adj->getRawOriginal('status'));
        $this->assertNull($adj->stock_movement_id);

        // Approving posts the movement and flips to approved.
        $approved = $this->svc->approve($adj->fresh(), $this->admin);
        $this->assertSame('approved', $approved->getRawOriginal('status'));
        $this->assertNotNull($approved->stock_movement_id);
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
}
