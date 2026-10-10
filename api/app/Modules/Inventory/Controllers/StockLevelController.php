<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Resources\MaterialReservationResource;
use App\Modules\Inventory\Resources\StockLevelResource;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;

class StockLevelController
{
    public function reservations(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => 'nullable|string|max:100', 'work_order_id' => 'nullable|string',
            'item_id' => 'nullable|string', 'status' => 'nullable|in:reserved,issued,released',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'group_by' => 'nullable|in:work_order',
        ]);
        $query = MaterialReservation::query()->with(['item', 'location.zone.warehouse', 'workOrder:id,wo_number']);
        foreach (['work_order_id' => WorkOrder::class, 'item_id' => Item::class] as $field => $model) {
            if ($request->filled($field)) {
                $id = HashIdFilter::decode($request->input($field), $model);
                if (! $id) {
                    throw ValidationException::withMessages([$field => 'Select a valid record.']);
                }
                $query->where($field, $id);
            }
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($search = trim((string) $request->input('search'))) {
            $query->where(fn ($q) => $q
                ->whereHas('workOrder', fn ($wo) => $wo->where('wo_number', 'ilike', '%'.$search.'%'))
                ->orWhereHas('item', fn ($item) => $item->where('code', 'ilike', '%'.$search.'%')->orWhere('name', 'ilike', '%'.$search.'%')));
        }

        if ($request->input('group_by') === 'work_order') {
            $groups = (clone $query)->toBase()->select('work_order_id')
                ->selectRaw('MAX(id) AS latest_id')->groupBy('work_order_id')
                ->orderByDesc('latest_id')->paginate($request->integer('per_page', 50));
            $ids = $groups->getCollection()->pluck('work_order_id');
            $materials = (clone $query)->where(function ($q) use ($ids): void {
                $q->whereIn('work_order_id', $ids->filter()->all());
                if ($ids->contains(null)) {
                    $q->orWhereNull('work_order_id');
                }
            })->orderByDesc('id')->get()->groupBy(fn ($row) => $row->work_order_id ?? 'unlinked');
            $groups->through(function ($group) use ($materials, $request): array {
                $lines = $materials->get($group->work_order_id ?? 'unlinked', collect());
                $wo = $lines->first()?->workOrder;

                return [
                    'id' => $wo?->hash_id ?? 'unlinked',
                    'work_order' => $wo ? ['id' => $wo->hash_id, 'wo_number' => $wo->wo_number] : null,
                    'materials' => $lines->map(fn ($line) => (new MaterialReservationResource($line))->toArray($request))->values()->all(),
                ];
            });

            return JsonResource::collection($groups);
        }

        return MaterialReservationResource::collection(
            $query->orderByDesc('id')->paginate($request->integer('per_page', 50))
        );
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:200', 'search' => 'nullable|string|max:200', 'item_id' => 'nullable|string', 'warehouse_id' => 'nullable|string']);
        $q = StockLevel::query()->with(['item', 'location.zone.warehouse']);
        if ($request->filled('item_id')) {
            $iid = HashIdFilter::decode($request->input('item_id'), Item::class);
            if (! $iid) {
                throw ValidationException::withMessages(['item_id' => 'Select a valid item.']);
            }
            $q->where('item_id', $iid);
        }
        if ($request->filled('warehouse_id')) {
            $wid = HashIdFilter::decode($request->input('warehouse_id'), Warehouse::class);
            if (! $wid) {
                throw ValidationException::withMessages(['warehouse_id' => 'Select a valid warehouse.']);
            }
            $q->whereHas('location.zone.warehouse', fn ($w) => $w->where('id', $wid));
        }
        if ($request->filled('item_type')) {
            $q->whereHas('item', fn ($i) => $i->where('item_type', $request->input('item_type')));
        }
        if ($request->boolean('low_only')) {
            $q->whereHas('item', function ($i) {
                $i->whereRaw('(SELECT COALESCE(SUM(quantity - reserved_quantity), 0) FROM stock_levels sl WHERE sl.item_id = items.id) <= items.reorder_point');
            });
        }
        if ($search = trim((string) $request->input('search'))) {
            $q->whereHas('item', fn ($item) => $item->where('code', 'ilike', '%'.$search.'%')->orWhere('name', 'ilike', '%'.$search.'%'));
        }

        $levels = $q->orderByDesc('quantity')->orderBy('id')->paginate(min((int) $request->input('per_page', 50), 200));
        $availability = app(StockLocationSummaryService::class)->availabilityByStockLevel($levels->getCollection());
        foreach ($levels->getCollection() as $level) {
            $level->setAttribute('availability_summary', $availability->get($level->getKey()));
        }

        return StockLevelResource::collection($levels);
    }
}
