<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\Uom;

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
    /** Physical quantities use the item base unit after conversion. */
    public function assertBaseQuantity(Item $item, string $quantity): void
    {
        if (! preg_match('/^\d+(?:\.\d{1,6})?$/D', $quantity)) {
            throw new BusinessRuleException('Quantity must be a non-negative decimal number.');
        }
        if (in_array(strtoupper(trim((string) $item->unit_of_measure)), ['PC', 'PCS', 'PIECE', 'PIECES', 'EA', 'EACH', 'UNIT', 'UNITS', 'SET', 'SETS'], true)
            && bccomp($quantity, bcdiv($quantity, '1', 0), 6) !== 0) {
            throw new BusinessRuleException('Piece quantities must be whole numbers.');
        }
    }

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
        return $this->toBaseWithFactor($item, $qty, $fromUomCode)['quantity'];
    }

    /**
     * Convert a quantity and return the factor used for document history.
     *
     * @return array{quantity:string,factor:string}
     */
    public function toBaseWithFactor(Item $item, string $qty, ?string $fromUomCode = null): array
    {
        $baseCode = (string) $item->unit_of_measure;

        if ($fromUomCode === null || $this->sameCode($fromUomCode, $baseCode)) {
            return ['quantity' => bcadd($qty, '0', 6), 'factor' => '1.000000'];
        }

        $factor = $this->factor($item, $fromUomCode, $baseCode);

        return ['quantity' => bcmul($qty, $factor, 6), 'factor' => $factor];
    }

    /**
     * Resolve the conversion factor (base units per one from-unit) for an item.
     *
     * @throws BusinessRuleException when the uom codes are unknown or no
     *                               conversion row exists for the
     *                               (item, from→base) pair.
     */
    public function factor(Item $item, string $fromUomCode, string $toUomCode): string
    {
        $from = $this->uomByCode($fromUomCode);
        $to = $this->uomByCode($toUomCode);

        $conv = ItemUomConversion::query()
            ->where('item_id', $item->id)
            ->where('from_uom_id', $from->id)
            ->where('to_uom_id', $to->id)
            ->lockForUpdate()
            ->first();

        if (! $conv) {
            // Master data, not deployment config: the missing row is authored at
            // POST /items/{item}/uom-conversions, and the message already names
            // the item and both units. It arrives here from a GRN or issue-slip
            // line the operator typed, so it is squarely a 422.
            throw new BusinessRuleException(
                "No UOM conversion configured for item {$item->code}: {$fromUomCode} → {$toUomCode}."
            );
        }

        return (string) $conv->factor;
    }

    private function uomByCode(string $code): Uom
    {
        $uom = Uom::query()->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])->first();
        if (! $uom) {
            // $code reaches here from request payloads — `received_uom_code` on a
            // GRN line, `issued_uom_code` on an issue slip, `unit` on a BOM line
            // — so an unknown code is user input to correct, not a fault.
            throw new BusinessRuleException("Unknown unit of measure: {$code}.");
        }

        return $uom;
    }

    private function sameCode(string $a, string $b): bool
    {
        return strtoupper(trim($a)) === strtoupper(trim($b));
    }
}
