<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\MRP\Models\Bom;

/** Resolves active manufactured components consistently for planning and costing. */
final class BomManufacturedComponentResolver
{
    /** @var array<string, Bom|null> */
    private array $cache = [];

    public function reset(): void
    {
        $this->cache = [];
    }

    public function forItem(?Item $item): ?Bom
    {
        if ($item === null) {
            return null;
        }
        $bom = $this->resolveCode($item->code);
        if ($bom === null) {
            return null;
        }
        if ($item->item_type !== ItemType::FinishedGood) {
            throw new BusinessRuleException("Item {$item->code} matches an active manufactured product but is not a finished-good item.");
        }
        if (strcasecmp((string) $item->unit_of_measure, (string) $bom->product->unit_of_measure) !== 0) {
            throw new BusinessRuleException("Item {$item->code} base unit must match its manufactured product unit.");
        }

        return $bom;
    }

    public function forCode(?string $itemCode): ?Bom
    {
        return $this->forItem(Item::query()->where('code', $itemCode)->first());
    }

    private function resolveCode(?string $itemCode): ?Bom
    {
        if ($itemCode === null || $itemCode === '') {
            return null;
        }

        if (array_key_exists($itemCode, $this->cache)) {
            return $this->cache[$itemCode];
        }

        $product = Product::query()
            ->active()
            ->where('part_number', $itemCode)
            ->with('activeBom')
            ->first();

        $bom = $product?->activeBom;
        $product?->unsetRelation('activeBom');
        $bom?->setRelation('product', $product);

        return $this->cache[$itemCode] = $bom;
    }
}
