<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockAdjustmentReason;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingStockAdjustmentScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private StockAdjustmentService $adjustments;

    private Item $item;

    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'system_admin'], ['name' => 'System Admin']);
        $this->admin = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->adjustments = app(StockAdjustmentService::class);
        $this->item = Item::factory()->create(['is_active' => true]);
        $this->location = WarehouseLocation::factory()->create();

        StockLevel::query()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => '0.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '10.0000',
            'lock_version' => 0,
        ]);

        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 500);
    }

    public function test_pending_adjustment_prevents_location_reparenting_through_api(): void
    {
        $pending = $this->createPendingAdjustment('Pending adjustment protects its location assignment');
        $targetZone = WarehouseZone::factory()->create();
        $oldZoneId = (int) $this->location->zone_id;
        $movementCount = StockMovement::query()->count();
        $auditCount = AuditLog::query()->count();

        $this->actingAs($this->admin)
            ->putJson('/api/v1/inventory/locations/'.$this->location->hash_id, [
                'zone_id' => $targetZone->hash_id,
                'code' => $this->location->code,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Resolve pending stock adjustments before changing or deleting their warehouse, zone, or location.');

        $this->assertSame($oldZoneId, (int) $this->location->fresh()->zone_id);
        $this->assertStillPending($pending);
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_pending_adjustment_prevents_zone_reparenting_through_api(): void
    {
        $pending = $this->createPendingAdjustment('Pending adjustment protects its zone assignment');
        $zone = WarehouseZone::query()->findOrFail($this->location->zone_id);
        $targetWarehouse = Warehouse::factory()->create();
        $oldWarehouseId = (int) $zone->warehouse_id;
        $movementCount = StockMovement::query()->count();
        $auditCount = AuditLog::query()->count();

        $this->actingAs($this->admin)
            ->putJson('/api/v1/inventory/zones/'.$zone->hash_id, [
                'warehouse_id' => $targetWarehouse->hash_id,
                'name' => $zone->name,
                'code' => $zone->code,
                'zone_type' => $zone->getRawOriginal('zone_type'),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Resolve pending stock adjustments before changing or deleting their warehouse, zone, or location.');

        $this->assertSame($oldWarehouseId, (int) $zone->fresh()->warehouse_id);
        $this->assertStillPending($pending);
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    private function createPendingAdjustment(string $reason): StockAdjustment
    {
        $pending = $this->adjustments->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'in',
            qty: '100',
            unitCost: '10.00',
            reason: $reason,
            by: $this->admin,
            reasonCode: StockAdjustmentReason::FoundStock,
        );

        $this->assertSame('pending', $pending->getRawOriginal('status'));
        $this->assertNull($pending->stock_movement_id);

        return $pending;
    }

    private function assertStillPending(StockAdjustment $adjustment): void
    {
        $fresh = $adjustment->fresh();
        $this->assertSame('pending', $fresh->getRawOriginal('status'));
        $this->assertNull($fresh->stock_movement_id);
    }
}
