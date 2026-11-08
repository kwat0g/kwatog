<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Support\OutboxDispatchContext;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;
use App\Modules\Dashboard\Services\PurchasingDashboardRealtimeService;
use App\Modules\Inventory\Events\InventoryStockChanged;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Events\StockReservationChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Retires stock-derived caches before publishing one minimal refresh signal. */
class PublishInventoryStockChange
{
    public function __construct(private readonly InventoryDashboardCache $cache) {}

    public function handle(StockMovementCompleted $event): void
    {
        $this->publish();
    }

    public function handleReservation(StockReservationChanged $event): void
    {
        $this->publish();
    }

    /**
     * Retire stock-derived caches in the source transaction so the acting
     * request cannot read a pre-mutation summary while its outbox is pending.
     */
    public function invalidateSourceCaches(): void
    {
        $this->cache->invalidateAfterCommit();
        DashboardCacheGeneration::touch('dashboard:warehouse');
        DashboardCacheGeneration::touch('dashboard:purchasing');
        DashboardCacheGeneration::touch('widget-summary:inventory.low_stock');
        DashboardCacheGeneration::touch('widget-summary:material.reservations');
    }

    private function publish(): void
    {
        $version = $this->cache->version();
        app(PurchasingDashboardRealtimeService::class)->stage();
        $context = OutboxDispatchContext::payload();
        $eventId = $context['outbox_id'] ?? (string) Str::uuid();
        $occurredAt = now();

        DB::afterCommit(static function () use ($eventId, $version, $occurredAt): void {
            event(new InventoryStockChanged($eventId, $version, $occurredAt));
        });
    }
}
