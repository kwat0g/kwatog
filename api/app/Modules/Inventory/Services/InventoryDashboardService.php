<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Support\Money;
use App\Common\Services\SettingsService;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryDashboardService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function summary(): array
    {
        return Cache::remember('inv:dashboard:summary', 30, fn () => $this->compute());
    }

    private function compute(): array
    {
        $totalStockValue = (string) DB::table('stock_levels')
            ->selectRaw('COALESCE(SUM(quantity * weighted_avg_cost), 0) AS v')
            ->value('v');

        // Aggregate availability per item.
        $availabilities = DB::table('stock_levels')
            ->select('item_id', DB::raw('SUM(quantity - reserved_quantity) AS available'))
            ->groupBy('item_id')
            ->pluck('available', 'item_id');

        $items = Item::query()->where('is_active', true)
            ->select('id', 'code', 'name', 'reorder_point', 'safety_stock', 'lead_time_days', 'is_critical')
            ->get();

        $lowStockItemIds = $items
            ->filter(function (Item $item) use ($availabilities): bool {
                $available = (string) ($availabilities[$item->id] ?? '0.000');

                return bccomp($available, (string) $item->reorder_point, 3) <= 0;
            })
            ->pluck('id')
            ->all();
        $openPrByItem = $this->latestOpenPurchaseRequests($lowStockItemIds);
        $openPoByItem = $this->latestOpenPurchaseOrders($lowStockItemIds);

        $belowReorder = 0;
        $critical = 0;
        $lowStockAlerts = [];
        foreach ($items as $item) {
            $available = (string) ($availabilities[$item->id] ?? '0.000');
            $safety = (string) $item->safety_stock;
            $reorder = (string) $item->reorder_point;
            if (bccomp($available, $safety, 3) <= 0) {
                $critical++;
            } elseif (bccomp($available, $reorder, 3) <= 0) {
                $belowReorder++;
            }
            if (bccomp($available, $reorder, 3) <= 0) {
                $openPr = $openPrByItem[$item->id] ?? null;
                $openPo = $openPoByItem[$item->id] ?? null;

                $lowStockAlerts[] = [
                    'item_id' => $item->hash_id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'available' => $available,
                    'reorder_point' => (string) $item->reorder_point,
                    'safety_stock' => (string) $item->safety_stock,
                    'lead_time_days' => (int) $item->lead_time_days,
                    'is_critical' => (bool) $item->is_critical,
                    'severity' => bccomp($available, $safety, 3) <= 0 ? 'critical' : 'low',
                    'open_pr' => $openPr ? ['number' => $openPr->pr_number, 'status' => $openPr->status?->value, 'status_label' => $openPr->status?->label()] : null,
                    'open_po' => $openPo ? ['number' => $openPo->po_number, 'status' => $openPo->status?->value, 'status_label' => $openPo->status?->label()] : null,
                ];
            }
        }

        // Top-10 by deficit ratio (smaller available - safety = more urgent).
        usort($lowStockAlerts, function ($a, $b) {
            $da = bcsub((string) $a['available'], (string) $a['safety_stock'], 3);
            $db = bcsub((string) $b['available'], (string) $b['safety_stock'], 3);

            return bccomp($da, $db, 3);
        });
        $lowStockAlerts = array_slice($lowStockAlerts, 0, 10);

        $pendingGrns = GoodsReceiptNote::query()
            ->where('status', GrnStatus::PendingQc)->count();

        $recentMovements = StockMovement::query()
            ->with(['item:id,code,name', 'fromLocation:id,code', 'toLocation:id,code'])
            ->orderByDesc('created_at')
            ->limit(20)->get();

        $historyDays = $this->settings->requiredInt('inventory.dashboard.consumption_history_days', 1);
        $thirtyDaysAgo = now()->subDays($historyDays);
        $topConsumed = DB::table('stock_movements')
            ->join('items', 'items.id', '=', 'stock_movements.item_id')
            ->where('stock_movements.movement_type', StockMovementType::MaterialIssue->value)
            ->where('stock_movements.created_at', '>=', $thirtyDaysAgo)
            ->groupBy('items.id', 'items.code', 'items.name', 'items.unit_of_measure')
            ->select('items.id', 'items.code', 'items.name', 'items.unit_of_measure',
                DB::raw('SUM(stock_movements.quantity) AS qty'),
                DB::raw('SUM(stock_movements.total_cost) AS total_value')
            )
            ->orderByDesc('qty')
            ->limit(10)->get();

        return [
            'consumption_history_days' => $historyDays,
            'total_stock_value' => Money::round2($totalStockValue),
            'items_below_reorder' => $belowReorder,
            'items_critical' => $critical,
            'pending_grns' => $pendingGrns,
            'low_stock_alerts' => $lowStockAlerts,
            'recent_movements' => $recentMovements->map(fn ($m) => [
                'id' => $m->hash_id,
                'created_at' => $m->created_at?->toIso8601String(),
                'movement_type' => $m->movement_type?->value,
                'movement_type_label' => Str::headline((string) $m->movement_type?->value),
                'item' => $m->item ? ['code' => $m->item->code, 'name' => $m->item->name] : null,
                'quantity' => (string) $m->quantity,
                'unit_cost' => (string) $m->unit_cost,
                'total_cost' => (string) $m->total_cost,
                'from_location' => $m->fromLocation?->code,
                'to_location' => $m->toLocation?->code,
            ])->all(),
            'top_consumed_materials' => $topConsumed->map(fn ($item) => [
                'id' => app('hashids')->encode((int) $item->id),
                'code' => $item->code,
                'name' => $item->name,
                'unit_of_measure' => $item->unit_of_measure,
                'qty' => (string) $item->qty,
                'total_value' => (string) $item->total_value,
            ])->all(),
        ];
    }

    /** @param list<int|string> $itemIds
     *  @return array<int, PurchaseRequest>
     */
    private function latestOpenPurchaseRequests(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $byItem = [];
        $rows = PurchaseRequest::query()
            ->join('purchase_request_items', 'purchase_request_items.purchase_request_id', '=', 'purchase_requests.id')
            ->whereIn('purchase_request_items.item_id', $itemIds)
            ->whereNull('purchase_requests.deleted_at')
            ->whereIn('purchase_requests.status', [
                PurchaseRequestStatus::Draft,
                PurchaseRequestStatus::Pending,
                PurchaseRequestStatus::Approved,
            ])
            ->orderByDesc('purchase_requests.id')
            ->get([
                'purchase_requests.id',
                'purchase_requests.pr_number',
                'purchase_requests.status',
                'purchase_request_items.item_id as source_item_id',
            ]);

        foreach ($rows as $row) {
            $byItem[(int) $row->source_item_id] ??= $row;
        }

        return $byItem;
    }

    /** @param list<int|string> $itemIds
     *  @return array<int, PurchaseOrder>
     */
    private function latestOpenPurchaseOrders(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $byItem = [];
        $rows = PurchaseOrder::query()
            ->join('purchase_order_items', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->whereIn('purchase_order_items.item_id', $itemIds)
            ->whereNull('purchase_orders.deleted_at')
            ->whereIn('purchase_orders.status', PurchaseOrderStatus::open())
            ->orderByDesc('purchase_orders.id')
            ->get([
                'purchase_orders.id',
                'purchase_orders.po_number',
                'purchase_orders.status',
                'purchase_order_items.item_id as source_item_id',
            ]);

        foreach ($rows as $row) {
            $byItem[(int) $row->source_item_id] ??= $row;
        }

        return $byItem;
    }
}
