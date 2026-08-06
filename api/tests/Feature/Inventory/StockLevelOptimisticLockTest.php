<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Common\Exceptions\BusinessRuleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * F-15 — `stock_levels.lock_version` must be a live optimistic lock, not a
 * write-only counter.
 *
 * 1. A movement that passes the version it read succeeds and bumps the row.
 * 2. A movement that passes a stale version is rejected (BusinessRuleException).
 * 3. reserve() / release() bump the version too — they mutate the ledger row.
 * 4. The API resource exposes lock_version so clients can read-then-write.
 */
class StockLevelOptimisticLockTest extends TestCase
{
    use RefreshDatabase;

    private StockMovementService $svc;
    private Item $item;
    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([StockMovementCompleted::class]);

        $this->svc      = app(StockMovementService::class);
        $this->item     = Item::factory()->create();
        $this->location = WarehouseLocation::factory()->create();
    }

    private function receipt(string $qty, string $unitCost, ?int $expectedToVersion = null): void
    {
        $this->svc->move(new StockMovementInput(
            type:                StockMovementType::GrnReceipt,
            itemId:              (int) $this->item->id,
            quantity:            $qty,
            fromLocationId:      null,
            toLocationId:        (int) $this->location->id,
            unitCost:            $unitCost,
            referenceType:       'test',
            expectedToVersion:   $expectedToVersion,
        ));
    }

    public function test_movement_with_current_version_succeeds_and_bumps(): void
    {
        $this->receipt('10.000', '10.00');
        $level = $this->svc->currentLevel($this->item->id, $this->location->id);

        $this->assertSame(1, (int) $level->lock_version, 'first receipt bumps version to 1');

        $this->receipt('5.000', '12.00', (int) $level->lock_version);

        $fresh = $this->svc->currentLevel($this->item->id, $this->location->id);
        $this->assertSame('15.000', (string) $fresh->quantity);
        $this->assertSame(2, (int) $fresh->lock_version, 'second receipt bumps version again');
    }

    public function test_stale_version_is_rejected(): void
    {
        $this->receipt('10.000', '10.00');
        $level = $this->svc->currentLevel($this->item->id, $this->location->id);

        // Another transaction lands between the read and the write.
        $this->receipt('1.000', '10.00');
        $fresh = $this->svc->currentLevel($this->item->id, $this->location->id);
        $this->assertSame(2, (int) $fresh->lock_version);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('changed since it was read');

        $this->receipt('1.000', '10.00', (int) $level->lock_version);
    }

    public function test_reserve_and_release_bump_the_version(): void
    {
        $this->receipt('10.000', '10.00');
        $before = $this->svc->currentLevel($this->item->id, $this->location->id);
        $v0 = (int) $before->lock_version;

        $this->svc->reserve($this->item->id, $this->location->id, '4.000');
        $reserved = $this->svc->currentLevel($this->item->id, $this->location->id);
        $this->assertSame($v0 + 1, (int) $reserved->lock_version, 'reserve must bump lock_version');
        $this->assertSame('4.000', (string) $reserved->reserved_quantity);

        $this->svc->release($this->item->id, $this->location->id, '2.000');
        $released = $this->svc->currentLevel($this->item->id, $this->location->id);
        $this->assertSame($v0 + 2, (int) $released->lock_version, 'release must bump lock_version');
        $this->assertSame('2.000', (string) $released->reserved_quantity);
    }

    public function test_resource_exposes_lock_version(): void
    {
        $this->receipt('10.000', '10.00');

        $this->actingAs($this->user())
            ->getJson('/api/v1/inventory/stock-levels')
            ->assertOk()
            ->assertJsonPath('data.0.lock_version', 1);
    }

    private function user(): \App\Modules\Auth\Models\User
    {
        $role = \App\Modules\Auth\Models\Role::firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);
        $perm = \App\Modules\Auth\Models\Permission::firstOrCreate(
            ['slug' => 'inventory.view'],
            ['name' => 'Inventory view', 'module' => 'inventory'],
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);

        return \App\Modules\Auth\Models\User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
