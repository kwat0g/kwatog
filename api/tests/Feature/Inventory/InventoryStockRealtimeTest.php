<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Services\OutboxEventCodec;
use App\Common\Support\Money;
use App\Common\Support\OutboxDispatchContext;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\BadgeService;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\InventoryStockChanged;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Events\StockReservationChanged;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\InventoryDashboardCache;
use App\Modules\Inventory\Services\PublishInventoryStockChange;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StockReservationRealtimeService;
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryStockRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $transactions = new DatabaseTransactionsManager;
        app()->instance('db.transactions', $transactions);
        DB::connection()->setTransactionManager($transactions);
        Cache::store('generations')->put('inv:dashboard:summary.version', (string) Str::uuid(), 604800);
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_committed_stock_movement_invalidates_dashboard_generation_and_publishes_only_a_ping(): void
    {
        $cache = app(InventoryDashboardCache::class);
        $this->assertSame('before', $cache->remember(fn () => 'before'));
        $versionBefore = $cache->version();
        $warehouseGenerationBefore = DashboardCacheGeneration::current('dashboard:warehouse');
        $lowStockGenerationBefore = DashboardCacheGeneration::current('widget-summary:inventory.low_stock');
        $reservationGenerationBefore = DashboardCacheGeneration::current('widget-summary:material.reservations');

        Event::fake([InventoryStockChanged::class]);
        DB::transaction(function () use ($cache, $versionBefore, $warehouseGenerationBefore): void {
            app(PublishInventoryStockChange::class)->invalidateSourceCaches();
            OutboxDispatchContext::run('outbox-123', StockMovementCompleted::class, function (): void {
                $movement = new StockMovement;
                $movement->setAttribute('id', 91);
                app(PublishInventoryStockChange::class)->handle(
                    new StockMovementCompleted($movement),
                );
            });

            $this->assertSame($versionBefore, $cache->version());
            $this->assertSame($warehouseGenerationBefore, DashboardCacheGeneration::current('dashboard:warehouse'));
            Event::assertNotDispatched(InventoryStockChanged::class);
        });

        $this->assertNotSame($versionBefore, $cache->version());
        $this->assertNotSame($warehouseGenerationBefore, DashboardCacheGeneration::current('dashboard:warehouse'));
        $this->assertNotSame($lowStockGenerationBefore, DashboardCacheGeneration::current('widget-summary:inventory.low_stock'));
        $this->assertNotSame($reservationGenerationBefore, DashboardCacheGeneration::current('widget-summary:material.reservations'));
        $this->assertSame('after', $cache->remember(fn () => 'after'));
        Event::assertDispatched(InventoryStockChanged::class, function (InventoryStockChanged $event): bool {
            return $event->broadcastWith()['event_id'] === 'outbox-123'
                && $event->broadcastWith()['entity_type'] === 'inventory_stock'
                && ! array_key_exists('movement_id', $event->broadcastWith())
                && ! array_key_exists('quantity', $event->broadcastWith());
        });
    }

    public function test_dashboard_cache_is_fresh_after_stock_movement_before_outbox_delivery(): void
    {
        Bus::fake([DispatchOutboxMessage::class]);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $cache = app(InventoryDashboardCache::class);
        $readStockValue = static fn (): string => $cache->remember(
            static fn (): string => Money::round2((string) DB::table('stock_levels')
                ->selectRaw('COALESCE(SUM(quantity * weighted_avg_cost), 0) AS v')
                ->value('v')),
        );

        $this->assertSame('0.00', $readStockValue());

        app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::GrnReceipt,
            itemId: $item->id,
            quantity: '10',
            toLocationId: $location->id,
            unitCost: '5.00',
        ));

        Bus::assertDispatched(DispatchOutboxMessage::class);
        $this->assertSame('50.00', $readStockValue());
    }

    public function test_reservation_change_retires_stock_widget_caches_and_publishes_only_a_ping(): void
    {
        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode(new StockReservationChanged(17, 23, 'reserved'));
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(StockReservationChanged::class, $decoded);
        $this->assertSame([17, 23, 'reserved'], [$decoded->itemId, $decoded->locationId, $decoded->action]);

        Bus::fake([DispatchOutboxMessage::class]);
        Event::fake([InventoryStockChanged::class]);
        $warehouseBefore = DashboardCacheGeneration::current('dashboard:warehouse');
        $lowStockBefore = DashboardCacheGeneration::current('widget-summary:inventory.low_stock');
        $reservationsBefore = DashboardCacheGeneration::current('widget-summary:material.reservations');

        DB::transaction(function () use ($warehouseBefore): void {
            app(StockReservationRealtimeService::class)->stage(17, 23, 'reserved');

            $this->assertSame($warehouseBefore, DashboardCacheGeneration::current('dashboard:warehouse'));
            Event::assertNotDispatched(InventoryStockChanged::class);
        });

        $this->assertNotSame($warehouseBefore, DashboardCacheGeneration::current('dashboard:warehouse'));
        $this->assertNotSame($lowStockBefore, DashboardCacheGeneration::current('widget-summary:inventory.low_stock'));
        $this->assertNotSame($reservationsBefore, DashboardCacheGeneration::current('widget-summary:material.reservations'));
        Bus::assertDispatched(DispatchOutboxMessage::class);
        OutboxDispatchContext::run('reservation-outbox-1', StockReservationChanged::class, function (): void {
            app(PublishInventoryStockChange::class)->handleReservation(
                new StockReservationChanged(17, 23, 'reserved'),
            );
        });
        Event::assertDispatched(InventoryStockChanged::class, function (InventoryStockChanged $event): bool {
            return $event->broadcastWith()['event_id'] === 'reservation-outbox-1'
                && $event->broadcastWith()['entity_type'] === 'inventory_stock'
                && ! array_key_exists('item_id', $event->broadcastWith())
                && ! array_key_exists('location_id', $event->broadcastWith());
        });
    }

    public function test_rolled_back_stock_change_does_not_invalidate_or_publish(): void
    {
        $cache = app(InventoryDashboardCache::class);
        $this->assertSame('before', $cache->remember(fn () => 'before'));
        $versionBefore = $cache->version();
        Event::fake([InventoryStockChanged::class]);

        DB::beginTransaction();
        app(PublishInventoryStockChange::class)->invalidateSourceCaches();
        OutboxDispatchContext::run('outbox-rollback', StockMovementCompleted::class, function (): void {
            app(PublishInventoryStockChange::class)->handle(new StockMovementCompleted(new StockMovement));
        });
        DB::rollBack();

        $this->assertSame($versionBefore, $cache->version());
        $this->assertSame('before', $cache->remember(fn () => 'should not replace'));
        Event::assertNotDispatched(InventoryStockChanged::class);
    }

    public function test_stock_generation_retires_badge_snapshots_before_refetch(): void
    {
        $user = User::factory()->create();
        $cache = app(InventoryDashboardCache::class);
        $badgeVersion = BadgeService::version();
        $stockVersion = $cache->version();
        $staleSnapshot = ['stale_stock_badge' => true];

        Cache::put("badges.user.{$user->id}.v{$badgeVersion}.s{$stockVersion}", $staleSnapshot, 30);
        $badges = app(BadgeService::class);
        $this->assertSame($staleSnapshot, $badges->for($user));

        $cache->invalidateAfterCommit();

        $this->assertNotSame($staleSnapshot, $badges->for($user));
    }

    public function test_inventory_stock_signal_uses_the_authorized_private_dashboard_channel_and_broadcast_lane(): void
    {
        $event = new InventoryStockChanged('event-1', 'generation-1', now());

        $channels = $event->broadcastOn();
        $this->assertCount(7, $channels);
        $this->assertContainsOnlyInstancesOf(PrivateChannel::class, $channels);
        $this->assertSame([
            'private-inventory.dashboard',
            'private-dashboard.warehouse',
            'private-entity.warehouse_map.visible',
            'private-entity.item.visible',
            'private-entity.work_order.visible',
            'private-entity.maintenance_work_order.visible',
            'private-entity.ncr.visible',
        ], array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $channels,
        ));
        $this->assertSame('.InventoryStockChanged', '.'.$event->broadcastAs());
        $this->assertSame('redis', $event->broadcastConnection());
        $this->assertSame('broadcasts', $event->broadcastQueue());
    }
}
