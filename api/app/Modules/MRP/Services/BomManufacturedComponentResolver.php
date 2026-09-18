<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Modules\CRM\Models\Product;
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
        return $this->forCode($item?->code);
    }

    public function forCode(?string $itemCode): ?Bom
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

        return $this->cache[$itemCode] = $product?->activeBom;
    }
}
