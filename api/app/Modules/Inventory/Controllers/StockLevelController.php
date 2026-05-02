<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Resources\StockLevelResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockLevelController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = StockLevel::query()->with(['item', 'location.zone.warehouse']);
        if ($request->filled('item_id')) {
            $iid = HashIdFilter::decode($request->input('item_id'), Item::class);
            if ($iid) $q->where('item_id', $iid);
        }
        if ($request->filled('warehouse_id')) {
            $wid = HashIdFilter::decode($request->input('warehouse_id'), Warehouse::class);
            if ($wid) $q->whereHas('location.zone.warehouse', fn ($w) => $w->where('id', $wid));
        }
        if ($request->filled('item_type')) {
            $q->whereHas('item', fn ($i) => $i->where('item_type', $request->input('item_type')));
        }
        if ($request->boolean('low_only')) {
            $q->whereHas('item', function ($i) {
                $i->whereRaw('(SELECT COALESCE(SUM(quantity - reserved_quantity), 0) FROM stock_levels sl WHERE sl.item_id = items.id) <= items.reorder_point');
            });
        }
        return StockLevelResource::collection(
            $q->orderByDesc('quantity')->paginate(min((int) $request->input('per_page', 50), 200))
        );
    }
}
