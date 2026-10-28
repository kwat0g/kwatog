<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\Concerns\DashboardQueries;
use App\Modules\Dashboard\Support\PanelGate;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\TransferOrderStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\InventoryDashboardService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4.1 extraction — Warehouse Staff dashboard.
 * Owns: warehouse, lowStockItemCount, warehouseIncomingQueue,
 *       warehouseOutgoingQueue, warehouseLowStockAlerts, warehouseZoneUtilization.
 */
class WarehouseDashboardService
{
    use DashboardQueries;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PanelGate $gate,
    ) {}

    private const CACHE_TTL = 30;

    public function warehouse(User $user): array
    {
        return Cache::remember("dashboard:warehouse:{$user->id}", self::CACHE_TTL, function () use ($user) {
            return [
                'kpis' => $this->gate->kpis($user, [
                    ['inventory.view', fn () => $this->kpi('Pending GRNs', (string) $this->safeCount('goods_receipt_notes', fn ($q) => $q
                        ->whereNotIn('status', [GrnStatus::Accepted->value, GrnStatus::Rejected->value])), 'count')],
                    ['inventory.view', fn () => $this->kpi('Issues Today', (string) $this->safeCount('stock_movements', fn ($q) => $q
                        ->where('movement_type', StockMovementType::MaterialIssue->value)->whereDate('created_at', today())), 'count')],
                    ['inventory.view', fn () => $this->kpi('Low Stock Items', (string) $this->lowStockItemCount(), 'count')],
                    ['inventory.view', fn () => $this->kpi('Pending Transfers', (string) $this->safeCount('transfer_orders', fn ($q) => $q
                        ->where('status', TransferOrderStatus::Pending->value)), 'count')],
                ]),
                'panels' => $this->gate->panels($user, [
                    // Configured thresholds, not data.
                    'delivery_horizon_days' => [null, fn () => $this->settings->requiredInt('dashboard.widgets.delivery_horizon_days', 0)],
                    'zone_utilization_warning_pct' => [null, fn () => round($this->settings->requiredFloat('inventory.dashboard.zone_utilization_warning_ratio', 0, 1) * 100, 1)],
                    'zone_utilization_critical_pct' => [null, fn () => round($this->settings->requiredFloat('inventory.dashboard.zone_utilization_critical_ratio', 0, 1) * 100, 1)],
                    // Inbound POs awaiting receipt. Gated on inventory rather
                    // than purchasing: the panel exists to drive GRN creation,
                    // which is exactly what warehouse_staff holds.
                    'incoming_queue' => ['inventory.view', fn () => $this->warehouseIncomingQueue()],
                    // Deliveries joined to sales orders and CUSTOMER names — the
                    // one panel here that is not inventory data. Gated on the
                    // narrow delivery read rather than supply_chain.view, so the
                    // warehouse can stage outbound loads without also receiving
                    // shipments, fleet and customs documents.
                    'outgoing_queue' => ['supply_chain.deliveries.view', fn () => $this->warehouseOutgoingQueue()],
                    'low_stock_alerts' => ['inventory.view', fn () => $this->warehouseLowStockAlerts()],
                    'zone_utilization' => ['inventory.view', fn () => $this->warehouseZoneUtilization()],
                ]),
            ];
        });
    }

    private function lowStockItemCount(): int
    {
        return (int) app(InventoryDashboardService::class)->summary()['items_low_stock'];
    }

    /**
     * @return array<int, array{id: string, po_number: string, vendor: string, items_count: int, expected_date: string|null}>
     */
    private function warehouseIncomingQueue(): array
    {
        if (! Schema::hasTable('purchase_orders') || ! Schema::hasTable('vendors')) {
            return [];
        }

        $itemsCountSub = Schema::hasTable('purchase_order_items')
            ? '(SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id)'
            : '0';

        $deliveryDays = $this->settings->requiredInt('dashboard.widgets.delivery_horizon_days', 0);
        return DB::table('purchase_orders as po')
            ->leftJoin('vendors as v', 'v.id', '=', 'po.vendor_id')
            ->whereIn('po.status', PurchaseOrderStatus::receivable())
            ->whereNotNull('po.expected_delivery_date')
            ->where('po.expected_delivery_date', '<=', today()->addDays($deliveryDays))
            ->select('po.id', 'po.po_number', 'v.name as vendor_name', 'po.expected_delivery_date',
                DB::raw("{$itemsCountSub} as items_count"))
            ->orderBy('po.expected_delivery_date')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'id' => app('hashids')->encode((int) $r->id),
                'po_number' => $r->po_number,
                'vendor' => $r->vendor_name ?? '—',
                'items_count' => (int) $r->items_count,
                'expected_date' => $r->expected_delivery_date,
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: string, so_number: string, customer: string, scheduled_date: string|null}>
     */
    private function warehouseOutgoingQueue(): array
    {
        if (! Schema::hasTable('deliveries') || ! Schema::hasTable('sales_orders') || ! Schema::hasTable('customers')) {
            return [];
        }

        return DB::table('deliveries as d')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'd.sales_order_id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->whereIn('d.status', ['scheduled', 'picking'])
            ->select('d.id', 'so.so_number', 'c.name as customer_name', 'd.scheduled_date')
            ->orderBy('d.scheduled_date')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'id' => app('hashids')->encode((int) $r->id),
                'so_number' => $r->so_number ?? '—',
                'customer' => $r->customer_name ?? '—',
                'scheduled_date' => $r->scheduled_date,
            ])
            ->all();
    }

    /**
     * @return array<int, array{item_code: string, item_name: string, current_stock: string, reorder_point: string, shortage: string, supplier_id: string|null, supplier_name: string|null}>
     */
    private function warehouseLowStockAlerts(): array
    {
        $items = collect(app(InventoryDashboardService::class)->summary()['low_stock_alerts']);
        $itemIds = $items->map(fn (array $item) => HashIdFilter::decode($item['item_id'], Item::class))
            ->filter()->values();
        $suppliers = collect();
        if ($itemIds->isNotEmpty() && Schema::hasTable('approved_suppliers') && Schema::hasTable('vendors')) {
            $suppliers = DB::table('approved_suppliers as ap')
                ->join('vendors as v', 'v.id', '=', 'ap.vendor_id')
                ->whereIn('ap.item_id', $itemIds)
                ->orderByDesc('ap.is_preferred')
                ->orderBy('ap.id')
                ->get(['ap.item_id', 'ap.vendor_id', 'v.name'])
                ->unique('item_id')
                ->keyBy('item_id');
        }

        return $items->map(function (array $item) use ($suppliers): array {
            $id = HashIdFilter::decode($item['item_id'], Item::class);
            $supplier = $id ? $suppliers->get($id) : null;

            return [
                'item_id' => $item['item_id'],
                'item_code' => $item['code'],
                'item_name' => $item['name'],
                'current_stock' => number_format((float) $item['available'], 2, '.', ''),
                'reorder_point' => number_format((float) $item['reorder_point'], 2, '.', ''),
                'shortage' => number_format(max(0.0, (float) $item['reorder_point'] - (float) $item['available']), 2, '.', ''),
                'supplier_id' => $supplier?->vendor_id ? app('hashids')->encode((int) $supplier->vendor_id) : null,
                'supplier_name' => $supplier?->name,
            ];
        })
            ->all();
    }

    /**
     * Zone occupancy grouped by warehouse and zone.
     *
     * @return array<int, array{warehouse_code: string, warehouse_name: string, zone: string, name: string, occupied: int, total: int, percent: int}>
     */
    private function warehouseZoneUtilization(): array
    {
        if (! Schema::hasTable('warehouse_locations') || ! Schema::hasTable('warehouse_zones') || ! Schema::hasTable('warehouses')) {
            return [];
        }

        return DB::table('warehouse_locations as wl')
            ->join('warehouse_zones as wz', 'wz.id', '=', 'wl.zone_id')
            ->join('warehouses as wh', 'wh.id', '=', 'wz.warehouse_id')
            ->where('wl.is_active', true)
            ->groupBy('wz.id', 'wh.code', 'wh.name', 'wz.code', 'wz.name')
            ->orderBy('wh.code')
            ->orderBy('wz.code')
            ->select(
                'wh.code as warehouse_code',
                'wh.name as warehouse_name',
                'wz.code as zone',
                'wz.name as name',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN wl.current_quantity > 0 THEN 1 ELSE 0 END) as occupied'),
            )
            ->get()
            ->map(fn ($r) => [
                'warehouse_code' => $r->warehouse_code,
                'warehouse_name' => $r->warehouse_name,
                'zone' => $r->zone,
                'name' => $r->name,
                'occupied' => (int) $r->occupied,
                'total' => (int) $r->total,
                'percent' => (int) round(((int) $r->occupied * 100) / max(1, (int) $r->total)),
            ])
            ->all();
    }
}
