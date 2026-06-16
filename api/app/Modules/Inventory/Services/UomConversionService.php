<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\Uom;
use RuntimeException;

/**
 * OGAMI-004 — Multi-UOM conversion.
 *
 * INVARIANT: stock is always stored in the item BASE uom (carried by
 * `items.unit_of_measure`). This service translates a quantity expressed in
 * some alternate purchase / issue uom into the base uom, applied only at the
 * edges (receiving, issuing) before the quantity reaches a stock movement.
 *
 *   factor = base units per ONE from-unit   (1 BAG = 25 KG → factor 25)
 *   base_qty = from_qty * factor
 */
class UomConversionService
{
    /**
     * Convert a quantity expressed in $fromUomCode into the item base uom.
     *
     * - Identity (returns the input rounded to 6 dp) when $fromUomCode is null
     *   or case-insensitively equal to the item base uom.
     * - Otherwise looks up the per-item conversion row (from → base) and
     *   multiplies by its factor.
     * - Throws when no conversion is configured for the (item, from→base) pair.
     *
     * @return string base-uom quantity, 6-decimal precision (BCMath string)
     */
    public function toBase(Item $item, string $qty, ?string $fromUomCode = null): string
    {
        $baseCode = (string) $item->unit_of_measure;

        if ($fromUomCode === null || $this->sameCode($fromUomCode, $baseCode)) {
            return bcadd($qty, '0', 6);
        }

        $factor = $this->factor($item, $fromUomCode, $baseCode);

        return bcmul($qty, $factor, 6);
    }

    /**
     * Resolve the conversion factor (base units per one from-unit) for an item.
     *
     * @throws RuntimeException when the uom codes are unknown or no conversion
     *                          row exists for the (item, from→base) pair.
     */
    public function factor(Item $item, string $fromUomCode, string $toUomCode): string
    {
        $from = $this->uomByCode($fromUomCode);
        $to   = $this->uomByCode($toUomCode);

        $conv = ItemUomConversion::query()
            ->where('item_id', $item->id)
            ->where('from_uom_id', $from->id)
            ->where('to_uom_id', $to->id)
            ->first();

        if (! $conv) {
            throw new RuntimeException(
                "No UOM conversion configured for item {$item->code}: {$fromUomCode} → {$toUomCode}."
            );
        }

        return (string) $conv->factor;
    }

    private function uomByCode(string $code): Uom
    {
        $uom = Uom::query()->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])->first();
        if (! $uom) {
            throw new RuntimeException("Unknown unit of measure: {$code}.");
        }
        return $uom;
    }

    private function sameCode(string $a, string $b): bool
    {
        return strtoupper(trim($a)) === strtoupper(trim($b));
    }
}
