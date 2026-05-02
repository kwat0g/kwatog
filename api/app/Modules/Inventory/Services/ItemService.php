<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\StockLevel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ItemService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = Item::query()->with('category:id,name,parent_id');

        // Subqueries for available/on-hand to prevent N+1.
        $q->withSum(['stockLevels as on_hand_quantity' => fn ($s) => $s], 'quantity')
          ->withSum(['stockLevels as reserved_quantity' => fn ($s) => $s], 'reserved_quantity');

        if (! empty($filters['item_type'])) {
            $q->where('item_type', $filters['item_type']);
        }
        if (! empty($filters['category_id'])) {
            $catId = HashIdFilter::decode($filters['category_id'], ItemCategory::class);
            if ($catId) $q->where('category_id', $catId);
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (isset($filters['is_critical']) && $filters['is_critical'] !== '') {
            $q->where('is_critical', filter_var($filters['is_critical'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('code', SearchOperator::like(), "%{$term}%")
                   ->orWhere('name', SearchOperator::like(), "%{$term}%");
            });
        }

        // Stock-status filter — applies post-query via item attribute.
        // For DB-side: critical = onhand-reserved <= safety_stock; low = <= reorder_point.
        if (! empty($filters['stock_status'])) {
            $status = $filters['stock_status'];
            $sub = StockLevel::query()
                ->selectRaw('item_id, SUM(quantity - reserved_quantity) as available')
                ->groupBy('item_id');
            $q->joinSub($sub, 'sl_avail', fn ($j) => $j->on('sl_avail.item_id', '=', 'items.id'));
            if ($status === 'critical') {
                $q->whereRaw('COALESCE(sl_avail.available, 0) <= items.safety_stock');
            } elseif ($status === 'low') {
                $q->whereRaw('COALESCE(sl_avail.available, 0) <= items.reorder_point AND COALESCE(sl_avail.available, 0) > items.safety_stock');
            } elseif ($status === 'ok') {
                $q->whereRaw('COALESCE(sl_avail.available, 0) > items.reorder_point');
            }
            $q->select('items.*');
        }

        return $q->orderBy('code')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Item $item): Item
    {
        return $item->load(['category', 'approvedSuppliers.vendor:id,name']);
    }

    public function create(array $data): Item
    {
        return DB::transaction(function () use ($data) {
            return Item::create($data);
        });
    }

    public function update(Item $item, array $data): Item
    {
        return DB::transaction(function () use ($item, $data) {
            // Forbid changing item_type or UOM if movements exist.
            if ($item->movements()->exists()) {
                if (isset($data['item_type']) && $data['item_type'] !== $item->item_type->value) {
                    throw new RuntimeException('Cannot change item_type after movements exist.');
                }
                if (isset($data['unit_of_measure']) && $data['unit_of_measure'] !== $item->unit_of_measure) {
                    throw new RuntimeException('Cannot change unit_of_measure after movements exist.');
                }
            }
            $item->update($data);
            return $item->fresh();
        });
    }

    public function delete(Item $item): void
    {
        if (! $item->canDelete()) {
            throw new RuntimeException('Cannot delete an item with stock or movements. Deactivate instead.');
        }
        $item->delete();
    }
}
