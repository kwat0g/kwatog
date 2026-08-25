<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\MRP\Exceptions\BomComponentIntegrityException;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;

/**
 * Shared guard for every path that costs or explodes a BOM.
 *
 * BOM rows can be created by imports and historical scripts as well as by the
 * editor, so the request validator is not a sufficient invariant boundary.
 */
final class BomComponentIntegrityService
{
    public function assertValid(Bom $bom): void
    {
        $lines = BomItem::query()
            ->where('bom_id', $bom->getKey())
            ->orderBy('sort_order')
            ->get(['id', 'item_id']);

        if ($lines->isEmpty()) {
            throw new BomComponentIntegrityException(
                'This BOM has no component lines and cannot be used for planning.'
            );
        }

        $itemIds = $lines->pluck('item_id')->map(static fn ($id): int => (int) $id);
        if ($itemIds->duplicates()->isNotEmpty()) {
            throw new BomComponentIntegrityException(
                'This BOM contains a duplicate component and cannot be used for planning.'
            );
        }

        /** @var array<int, Item> $items */
        $items = Item::withTrashed()
            ->whereIn('id', $itemIds->all())
            ->get()
            ->keyBy('id')
            ->all();

        foreach ($lines as $line) {
            $item = $items[(int) $line->item_id] ?? null;
            if ($item === null) {
                throw new BomComponentIntegrityException(
                    'A BOM component no longer exists and the BOM cannot be used for planning.'
                );
            }

            if ($item->trashed()) {
                throw new BomComponentIntegrityException(
                    "BOM component {$item->code} is archived and must be restored or replaced."
                );
            }

            if (! $item->is_active) {
                throw new BomComponentIntegrityException(
                    "BOM component {$item->code} is inactive and must be reactivated or replaced."
                );
            }
        }
    }
}
