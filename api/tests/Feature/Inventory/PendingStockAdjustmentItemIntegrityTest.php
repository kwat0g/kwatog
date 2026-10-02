<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Inventory\Services\StockCountService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PendingStockAdjustmentItemIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouseStaff;

    private User $financeOfficer;

    private User $systemAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->warehouseStaff = $this->userFor('warehouse_staff');
        $this->financeOfficer = $this->userFor('finance_officer');
        $this->systemAdmin = $this->userFor('system_admin');
    }

    public function test_pending_adjustment_remains_readable_after_legacy_item_soft_delete(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $adjustment = $this->createPendingAdjustment($item);

        // Simulates an already-imported or legacy open document whose item was archived outside ItemService.
        $item->delete();

        $this->actingAs($this->financeOfficer)
            ->getJson('/api/v1/inventory/stock-adjustments?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $adjustment->hash_id)
            ->assertJsonPath('data.0.item.code', $item->code);
    }

    public function test_item_cannot_be_deleted_while_a_stock_adjustment_is_pending(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $adjustment = $this->createPendingAdjustment($item);

        $this->actingAs($this->systemAdmin)
            ->deleteJson("/api/v1/inventory/items/{$item->hash_id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot delete an item with stock, movements, open stock counts, or pending stock adjustments. Deactivate instead.');

        $this->assertNull($item->fresh()->deleted_at);
        $this->assertDatabaseHas('stock_adjustments', [
            'id' => $adjustment->id,
            'status' => 'pending',
            'stock_movement_id' => null,
        ]);
    }

    public function test_item_cannot_be_deleted_while_a_draft_count_references_it(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create(['is_active' => true]);
        StockLevel::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '0.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '0.0000',
            'lock_version' => 0,
        ]);
        $sessionId = $this->createStockCount($location);

        $this->actingAs($this->systemAdmin)
            ->deleteJson("/api/v1/inventory/items/{$item->hash_id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot delete an item with stock, movements, open stock counts, or pending stock adjustments. Deactivate instead.');

        $this->assertNull($item->fresh()->deleted_at);
        $this->assertDatabaseHas('stock_count_sessions', [
            'id' => HashIdFilter::decode($sessionId, StockCountSession::class),
            'status' => 'draft',
        ]);
    }

    public function test_item_cannot_be_deleted_with_negative_stock_or_reserved_quantity(): void
    {
        foreach ([
            ['quantity' => '-1.000', 'reserved_quantity' => '0.000'],
            ['quantity' => '0.000', 'reserved_quantity' => '1.000'],
        ] as $balance) {
            $item = Item::factory()->create(['is_active' => true]);
            $location = WarehouseLocation::factory()->create(['is_active' => true]);
            StockLevel::query()->create($balance + [
                'item_id' => $item->id,
                'location_id' => $location->id,
                'weighted_avg_cost' => '0.0000',
                'lock_version' => 0,
            ]);

            $this->actingAs($this->systemAdmin)
                ->deleteJson("/api/v1/inventory/items/{$item->hash_id}")
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Cannot delete an item with stock, movements, open stock counts, or pending stock adjustments. Deactivate instead.');

            $this->assertNull($item->fresh()->deleted_at);
        }
    }

    public function test_item_cannot_be_deleted_from_an_open_count_with_zero_on_hand_stock(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create(['is_active' => true]);
        StockLevel::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '0.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '0.0000',
            'lock_version' => 0,
        ]);
        $sessionId = $this->startStockCount($location);

        $this->assertDatabaseHas('stock_count_items', [
            'item_id' => $item->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->systemAdmin)
            ->deleteJson("/api/v1/inventory/items/{$item->hash_id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot delete an item with stock, movements, open stock counts, or pending stock adjustments. Deactivate instead.');
    }

    public function test_legacy_open_count_keeps_item_identity_after_soft_delete(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create(['is_active' => true]);
        StockLevel::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '0.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '0.0000',
            'lock_version' => 0,
        ]);
        $sessionId = $this->startStockCount($location);

        // Simulates an already-imported or legacy open count whose item was archived outside ItemService.
        $item->delete();

        $this->actingAs($this->warehouseStaff)
            ->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.item.code', $item->code);
    }

    public function test_legacy_draft_count_with_deleted_item_cannot_be_started(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create(['is_active' => true]);
        StockLevel::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '0.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '0.0000',
            'lock_version' => 0,
        ]);
        $sessionId = $this->createStockCount($location);
        $item->delete();

        $this->actingAs($this->warehouseStaff)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/start")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A stock count cannot include a missing or deleted item. Restore the item or cancel the count.');

        $this->assertDatabaseHas('stock_count_sessions', [
            'id' => HashIdFilter::decode($sessionId, StockCountSession::class),
            'status' => 'draft',
        ]);
    }

    public function test_delete_waits_for_a_concurrent_adjustment_and_then_refuses_it(): void
    {
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }

        $item = Item::factory()->create(['is_active' => true]);
        $result = tempnam(sys_get_temp_dir(), 'pending-adjustment-item-delete-');
        $this->beginHeldItemLock($item->id);
        $adjustment = $this->createPendingAdjustment($item);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            $this->runInHarnessConnection(function () use ($item, $result): void {
                try {
                    app(ItemService::class)->delete(Item::query()->findOrFail($item->id));
                    file_put_contents($result, 'success');
                } catch (BusinessRuleException $e) {
                    file_put_contents($result, 'error:'.$e->getMessage());
                } catch (\Throwable $e) {
                    file_put_contents($result, 'unexpected:'.$e::class.':'.$e->getMessage());
                }
            });
            exit(0);
        }

        try {
            usleep(250000);
            $this->assertSame('', (string) @file_get_contents($result), 'The item delete must wait for the pending adjustment transaction.');
        } finally {
            $this->releaseHeldItemLock();
            pcntl_waitpid($pid, $status);
        }

        $this->assertSame(
            'error:Cannot delete an item with stock, movements, open stock counts, or pending stock adjustments. Deactivate instead.',
            (string) file_get_contents($result),
        );
        $this->assertNull($item->fresh()->deleted_at);
        $this->assertSame('pending', $adjustment->fresh()->status->value);
        @unlink($result);
    }

    public function test_stock_count_creation_waits_for_item_deletion_and_refuses_deleted_item(): void
    {
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }

        $item = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create(['is_active' => true]);
        StockLevel::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '0.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '0.0000',
            'lock_version' => 0,
        ]);
        $result = tempnam(sys_get_temp_dir(), 'count-item-delete-race-');
        $this->beginHeldItemLock($item->id);
        app(ItemService::class)->delete($item);
        $itemId = $item->id;
        $zoneId = $location->zone_id;
        $userId = $this->warehouseStaff->id;
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            $this->runInHarnessConnection(function () use ($result, $zoneId, $userId): void {
                try {
                    app(StockCountService::class)->createSession([
                        'title' => 'Concurrent item deletion count',
                        'scope' => 'zone',
                        'zone_id' => $zoneId,
                    ], User::query()->findOrFail($userId));
                    file_put_contents($result, 'created');
                } catch (BusinessRuleException $e) {
                    file_put_contents($result, 'error:'.$e->getMessage());
                }
            });
            exit(0);
        }

        try {
            usleep(250000);
            $this->assertSame('', (string) @file_get_contents($result), 'Count creation must wait for the item deletion transaction.');
        } finally {
            $this->releaseHeldItemLock();
            pcntl_waitpid($pid, $status);
        }

        $this->assertSame(
            'error:A stock count cannot include a missing or deleted item. Restore the item or cancel the count.',
            (string) file_get_contents($result),
        );
        $this->assertDatabaseMissing('stock_count_items', ['item_id' => $itemId]);
        @unlink($result);
    }

    private function createPendingAdjustment(Item $item): StockAdjustment
    {
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 1);
        $location = WarehouseLocation::factory()->create(['is_active' => true]);

        $this->actingAs($this->warehouseStaff)
            ->postJson('/api/v1/inventory/stock-adjustments', [
                'item_id' => $item->hash_id,
                'location_id' => $location->hash_id,
                'direction' => 'in',
                'quantity' => '2.000',
                'unit_cost' => '50.0000',
                'reason' => 'Pending adjustment keeps item history.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.stock_movement', null);

        return StockAdjustment::query()->where('item_id', $item->id)->firstOrFail();
    }

    private function startStockCount(WarehouseLocation $location): string
    {
        $session = $this->createStockCount($location);

        $this->actingAs($this->warehouseStaff)
            ->postJson("/api/v1/inventory/stock-counts/{$session}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        return $session;
    }

    private function createStockCount(WarehouseLocation $location): string
    {
        $session = $this->actingAs($this->warehouseStaff)
            ->postJson('/api/v1/inventory/stock-counts', [
                'title' => 'Item deletion integrity count',
                'scope' => 'zone',
                'zone_id' => $location->zone->hash_id,
            ])
            ->assertCreated()
            ->json('data.id');

        return $session;
    }

    private function userFor(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'is_active' => true,
        ]);
    }

    private function beginHeldItemLock(int $itemId): void
    {
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }

        DB::beginTransaction();
        DB::table('items')->where('id', $itemId)->lockForUpdate()->first();
    }

    private function releaseHeldItemLock(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::commit();
        }
    }

    /** @param callable(): void $callback */
    private function runInHarnessConnection(callable $callback): void
    {
        $connection = config('database.connections.'.config('database.default'));
        config(['database.connections.harness' => $connection, 'database.default' => 'harness']);
        DB::connection('harness')->getPdo();
        $callback();
    }
}
