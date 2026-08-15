<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\MRP\Models\Bom;

/** Resolves the active SOs whose demand can be affected by a planning change. */
class MrpScopeResolver
{
    /** @return list<int> */
    public function salesOrderIdsForProduct(int $productId): array
    {
        return $this->salesOrderIdsForProducts($this->includeParentProducts([$productId]));
    }

    /** @param list<int> $itemIds @return list<int> */
    public function salesOrderIdsForItems(array $itemIds): array
    {
        $productIds = Bom::query()
            ->active()
            ->whereHas('items', fn ($q) => $q->whereIn('item_id', array_values(array_unique(array_map('intval', $itemIds)))))
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($productIds === []) {
            return [];
        }

        return $this->salesOrderIdsForProducts($this->includeParentProducts($productIds));
    }

    /** @param list<int> $productIds @return list<int> */
    private function salesOrderIdsForProducts(array $productIds): array
    {
        return SalesOrderItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('salesOrder', fn ($q) => $q->whereIn('status', [
                'confirmed', 'in_production', 'partially_delivered',
            ]))
            ->pluck('sales_order_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * If a changed subassembly/product is consumed by another manufactured
     * product, include every active parent BOM up the demand tree.
     *
     * @param  list<int>  $seedProductIds
     * @return list<int>
     */
    private function includeParentProducts(array $seedProductIds): array
    {
        $all = array_values(array_unique(array_map('intval', $seedProductIds)));
        $frontier = $all;

        while ($frontier !== []) {
            $codes = Product::query()->whereIn('id', $frontier)->pluck('part_number')->all();
            if ($codes === []) {
                break;
            }

            $parents = Bom::query()
                ->active()
                ->whereHas('items.item', fn ($q) => $q->whereIn('code', $codes))
                ->pluck('product_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $frontier = array_values(array_diff(array_unique($parents), $all));
            $all = array_values(array_unique(array_merge($all, $frontier)));
        }

        return $all;
    }
}
