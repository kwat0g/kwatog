<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\Concerns\DashboardQueries;
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

    private const CACHE_TTL = 30;

    public function warehouse(User $user): array
    {
        return Cache::remember("dashboard:warehouse:{$user->id}", self::CACHE_TTL, function () {
            $pendingGrns      = $this->safeCount('goods_receipt_notes', fn ($q) => $q->where('status', 'pending'));
            $issuesToday      = $this->safeCount('stock_movements', fn ($q) => $q->where('movement_type', 'issue')->whereDate('created_at', today()));
            $lowStock         = $this->lowStockItemCount();
            $pendingTransfers = $this->safeCount('stock_movements', fn ($q) => $q->where('movement_type', 'transfer')->whereNull('to_location_id'));

            return [
                'kpis' => [
                    $this->kpi('Pending GRNs',      (string) $pendingGrns,      'count'),
                    $this->kpi('Issues Today',       (string) $issuesToday,      'count'),
                    $this->kpi('Low Stock Items',    (string) $lowStock,         'count'),
                    $this->kpi('Pending Transfers',  (string) $pendingTransfers, 'count'),
                ],
                'panels' => [
                    'incoming_queue'   => $this->warehouseIncomingQueue(),
                    'outgoing_queue'   => $this->warehouseOutgoingQueue(),
                    'low_stock_alerts' => $this->warehouseLowStockAlerts(),
                    'zone_utilization' => $this->warehouseZoneUtilization(),
                ],
            ];
        });
    }

    private function lowStockItemCount(): int
    {
        if (! Schema::hasTable('items') || ! Schema::hasTable('stock_levels')) return 0;
        return (int) DB::table('items')
            ->where('is_active', true)
            ->where('reorder_point', '>', 0)
            ->whereRaw('items.reorder_point > COALESCE((SELECT SUM(quantity - reserved_quantity) FROM stock_levels WHERE stock_levels.item_id = items.id), 0)')
            ->count();
    }

    /**
     * @return array<int, array{id: string, po_number: string, vendor: string, items_count: int, expected_date: string|null}>
     */
    private function warehouseIncomingQueue(): array
    {
        if (! Schema::hasTable('purchase_orders') || ! Schema::hasTable('vendors')) return [];

        $itemsCountSub = Schema::hasTable('purchase_order_items')
            ? '(SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id)'
            : '0';

        return DB::table('purchase_orders as po')
            ->leftJoin('vendors as v', 'v.id', '=', 'po.vendor_id')
            ->whereIn('po.status', ['sent', 'partial'])
            ->whereNotNull('po.expected_delivery_date')
            ->where('po.expected_delivery_date', '<=', today()->addDays(7))
            ->select('po.id', 'po.po_number', 'v.name as vendor_name', 'po.expected_delivery_date',
                DB::raw("{$itemsCountSub} as items_count"))
            ->orderBy('po.expected_delivery_date')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'id'            => app('hashids')->encode((int) $r->id),
                'po_number'     => $r->po_number,
                'vendor'        => $r->vendor_name ?? '—',
                'items_count'   => (int) $r->items_count,
                'expected_date' => $r->expected_delivery_date,
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: string, so_number: string, customer: string, scheduled_date: string|null}>
     */
    private function warehouseOutgoingQueue(): array
    {
        if (! Schema::hasTable('deliveries') || ! Schema::hasTable('sales_orders') || ! Schema::hasTable('customers')) return [];
        return DB::table('deliveries as d')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'd.sales_order_id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->whereIn('d.status', ['scheduled', 'picking'])
            ->select('d.id', 'so.so_number', 'c.name as customer_name', 'd.scheduled_date')
            ->orderBy('d.scheduled_date')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'id'             => app('hashids')->encode((int) $r->id),
                'so_number'      => $r->so_number ?? '—',
                'customer'       => $r->customer_name ?? '—',
                'scheduled_date' => $r->scheduled_date,
            ])
            ->all();
    }

    /**
     * @return array<int, array{item_code: string, item_name: string, current_stock: string, reorder_point: string, shortage: string, supplier_id: string|null, supplier_name: string|null}>
     */
    private function warehouseLowStockAlerts(): array
    {
        if (! Schema::hasTable('items') || ! Schema::hasTable('stock_levels')) return [];

        $availableSub = '(SELECT COALESCE(SUM(quantity - reserved_quantity), 0) FROM stock_levels WHERE stock_levels.item_id = items.id)';

        $query = DB::table('items')
            ->where('items.is_active', true)
            ->where('items.reorder_point', '>', 0)
            ->whereRaw("items.reorder_point > {$availableSub}")
            ->select(
                'items.id',
                'items.code',
                'items.name',
                'items.reorder_point',
                DB::raw("{$availableSub} as available"),
            );

        if (Schema::hasTable('approved_suppliers') && Schema::hasTable('vendors')) {
            $query->leftJoin('approved_suppliers as ap', 'ap.item_id', '=', 'items.id')
                  ->leftJoin('vendors as v', 'v.id', '=', 'ap.vendor_id')
                  ->addSelect('v.id as vendor_id', 'v.name as vendor_name')
                  ->groupBy('items.id', 'items.code', 'items.name', 'items.reorder_point', 'v.id', 'v.name');
        }

        return $query
            ->orderBy('items.name')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                $available = (float) $r->available;
                $reorder   = (float) $r->reorder_point;
                return [
                    'item_code'     => $r->code,
                    'item_name'     => $r->name,
                    'current_stock' => number_format($available, 2, '.', ''),
                    'reorder_point' => number_format($reorder, 2, '.', ''),
                    'shortage'      => number_format(max(0.0, $reorder - $available), 2, '.', ''),
                    'supplier_id'   => isset($r->vendor_id) && $r->vendor_id ? app('hashids')->encode((int) $r->vendor_id) : null,
                    'supplier_name' => $r->vendor_name ?? null,
                ];
            })
            ->all();
    }

    /**
     * Zone occupancy grouped by zone.
     *
     * @return array<int, array{zone: string, name: string, occupied: int, total: int, percent: int}>
     */
    private function warehouseZoneUtilization(): array
    {
        if (! Schema::hasTable('warehouse_locations') || ! Schema::hasTable('warehouse_zones')) return [];

        return DB::table('warehouse_locations as wl')
            ->join('warehouse_zones as wz', 'wz.id', '=', 'wl.zone_id')
            ->where('wl.is_active', true)
            ->groupBy('wz.id', 'wz.code', 'wz.name')
            ->orderBy('wz.code')
            ->select(
                'wz.code as zone',
                'wz.name as name',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN wl.current_quantity > 0 THEN 1 ELSE 0 END) as occupied'),
            )
            ->get()
            ->map(fn ($r) => [
                'zone'    => $r->zone,
                'name'    => $r->name,
                'occupied' => (int) $r->occupied,
                'total'   => (int) $r->total,
                'percent' => (int) round(((int) $r->occupied * 100) / max(1, (int) $r->total)),
            ])
            ->all();
    }
}
