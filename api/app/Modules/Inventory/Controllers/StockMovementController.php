<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Resources\StockMovementResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockMovementController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = StockMovement::query()->with(['item', 'fromLocation', 'toLocation', 'creator:id,name,role_id']);
        if ($request->filled('item_id')) {
            $iid = HashIdFilter::decode($request->input('item_id'), Item::class);
            if ($iid) $q->where('item_id', $iid);
        }
        if ($request->filled('movement_type')) $q->where('movement_type', $request->input('movement_type'));
        if ($request->filled('from')) $q->where('created_at', '>=', $request->input('from'));
        if ($request->filled('to'))   $q->where('created_at', '<=', $request->input('to').' 23:59:59');
        if ($request->filled('reference_type')) $q->where('reference_type', $request->input('reference_type'));

        return StockMovementResource::collection(
            $q->orderByDesc('created_at')->paginate(min((int) $request->input('per_page', 50), 200))
        );
    }
}
