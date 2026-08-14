<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\Money;
use App\Modules\MRP\Models\Bom;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Calculates and freezes the material-only standard cost for one BOM version.
 *
 * Quantities are converted to the component item's base UOM before costing.
 * The snapshot belongs to the BOM version, so later item-cost changes do not
 * rewrite historical BOM economics; callers can explicitly recalculate a
 * version when they want a new cost snapshot.
 */
class BomCostingService
{
    public function recalculate(Bom $bom): Bom
    {
        return DB::transaction(function () use ($bom): Bom {
            $bom->load(['items.item']);

            $total = Money::zero();
            $warnings = [];

            foreach ($bom->items as $line) {
                $item = $line->item;
                if ($item === null) {
                    throw new BusinessRuleException(
                        'Cannot cost BOM line '.$line->id.': the component item no longer exists.'
                    );
                }

                if (! $item->is_active) {
                    throw new BusinessRuleException(
                        "Cannot cost BOM {$bom->version}: item {$item->code} is inactive."
                    );
                }

                try {
                    $baseQuantity = $item->convertToBase(
                        (string) $line->effective_quantity,
                        (string) $line->unit,
                    );
                } catch (RuntimeException $e) {
                    throw new BusinessRuleException(
                        "Cannot cost item {$item->code}: {$e->getMessage()}"
                    );
                }

                $unitCost = (string) $item->standard_cost;
                $extendedCost = Money::round2(bcmul($baseQuantity, $unitCost, 8));

                $line->forceFill([
                    'cost_quantity' => bcadd($baseQuantity, '0', 6),
                    'unit_cost'     => $unitCost,
                    'extended_cost' => $extendedCost,
                ])->save();

                if (bccomp($unitCost, '0', 4) === 0) {
                    $warnings[] = [
                        'type'       => 'zero_standard_cost',
                        'item_code'  => $item->code,
                        'message'    => "Item {$item->code} has a zero standard cost.",
                    ];
                }

                $total = Money::add($total, $extendedCost);
            }

            $bom->forceFill([
                'material_cost' => $total,
                'cost_basis'    => 'standard_cost',
                'costed_at'     => now(),
                'cost_warnings' => $warnings,
            ])->save();

            return $bom->fresh()->load(['product', 'items.item']);
        });
    }
}
