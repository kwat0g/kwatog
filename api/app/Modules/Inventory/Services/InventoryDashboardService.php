<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InventoryDashboardService
{
    public function summary(): array
    {
        return Cache::remember('inv:dashboard:summary', 30, fn () => $this->compute());
    }

    private function compute(): array
    {
        $totalStockValue = (float) DB::table('stock_levels')
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

        $belowReorder = 0;
        $critical = 0;
        $lowStockAlerts = [];
        foreach ($items as $item) {
            $available = (float) ($availabilities[$item->id] ?? 0);
            $safety = (float) $item->safety_stock;
            $reorder = (float) $item->reorder_point;
            if ($available <= $safety) {
                $critical++;
            } elseif ($available <= $reorder) {
                $belowReorder++;
            }
            if ($available <= $reorder) {
                $openPr = PurchaseRequest::query()
                    ->whereHas('items', fn ($q) => $q->where('item_id', $item->id))
                    ->whereIn('status', [
                        PurchaseRequestStatus::Draft,
                        PurchaseRequestStatus::Pending,
                        PurchaseRequestStatus::Approved,
                    ])
                    ->orderByDesc('id')->first(['id', 'pr_number', 'status']);

                $openPo = PurchaseOrder::query()
                    ->whereHas('items', fn ($q) => $q->where('item_id', $item->id))
                    ->whereIn('status', [
                        PurchaseOrderStatus::Approved,
                        PurchaseOrderStatus::Sent,
                        PurchaseOrderStatus::PartiallyReceived,
                    ])
                    ->orderByDesc('id')->first(['id', 'po_number', 'status']);

                $lowStockAlerts[] = [
                    'item_id'         => $item->id,
                    'code'            => $item->code,
                    'name'            => $item->name,
                    'available'       => number_format($available, 3, '.', ''),
                    'reorder_point'   => (string) $item->reorder_point,
                    'safety_stock'    => (string) $item->safety_stock,
                    'lead_time_days'  => (int) $item->lead_time_days,
                    'is_critical'     => (bool) $item->is_critical,
                    'severity'        => $available <= $safety ? 'critical' : 'low',
                    'open_pr'         => $openPr ? ['number' => $openPr->pr_number, 'status' => (string) $openPr->status] : null,
                    'open_po'         => $openPo ? ['number' => $openPo->po_number, 'status' => (string) $openPo->status] : null,
                ];
            }
        }

        // Top-10 by deficit ratio (smaller available - safety = more urgent).
        usort($lowStockAlerts, function ($a, $b) {
            $da = (float) $a['available'] - (float) $a['safety_stock'];
            $db = (float) $b['available'] - (float) $b['safety_stock'];
            return $da <=> $db;
        });
        $lowStockAlerts = array_slice($lowStockAlerts, 0, 10);

        $pendingGrns = GoodsReceiptNote::query()
            ->where('status', GrnStatus::PendingQc)->count();

        $recentMovements = StockMovement::query()
            ->with(['item:id,code,name', 'fromLocation:id,code', 'toLocation:id,code'])
            ->orderByDesc('created_at')
            ->limit(20)->get();

        $thirtyDaysAgo = now()->subDays(30);
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
            'total_stock_value'      => number_format($totalStockValue, 2, '.', ''),
            'items_below_reorder'    => $belowReorder,
            'items_critical'         => $critical,
            'pending_grns'           => $pendingGrns,
            'low_stock_alerts'       => $lowStockAlerts,
            'recent_movements'       => $recentMovements->map(fn ($m) => [
                'id'             => $m->hash_id,
                'created_at'     => $m->created_at?->toIso8601String(),
                'movement_type'  => (string) $m->movement_type,
                'item'           => $m->item ? ['code' => $m->item->code, 'name' => $m->item->name] : null,
                'quantity'       => (string) $m->quantity,
                'unit_cost'      => (string) $m->unit_cost,
                'total_cost'     => (string) $m->total_cost,
                'from_location'  => $m->fromLocation?->code,
                'to_location'    => $m->toLocation?->code,
            ])->all(),
            'top_consumed_materials' => $topConsumed,
        ];
    }
}
