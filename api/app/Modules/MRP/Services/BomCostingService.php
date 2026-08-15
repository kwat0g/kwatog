<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\Money;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\MRP\Models\Bom;
use App\Modules\Production\Models\ProductRouting;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Calculates and freezes the full standard cost for one BOM version.
 *
 * Material lines use the component item's base-UOM standard cost. When a line
 * points to a manufactured product with an active BOM, that BOM's full cost
 * is rolled up instead of using the component item's standalone cost. Active
 * routing operations add labor, machine, and overhead costs from their cycle
 * time and hourly rates. Setup time is intentionally excluded from a per-unit
 * BOM cost because no production batch size is available for allocation.
 */
class BomCostingService
{
    public function recalculate(Bom $bom): Bom
    {
        return DB::transaction(function () use ($bom): Bom {
            $this->recalculateBom($bom, []);

            return $bom->fresh()->load(['product', 'items.item']);
        });
    }

    /**
     * @param list<int> $path
     * @return array{material_cost:string, labor_cost:string, machine_cost:string, overhead_cost:string, total_cost:string, warnings:list<array<string, string>>}
     */
    private function recalculateBom(Bom $bom, array $path): array
    {
        $productId = (int) $bom->product_id;
        if (in_array($productId, $path, true)) {
            throw new BusinessRuleException('Circular bill of materials detected while costing product '.$productId.'.');
        }

        $bom->load(['items.item']);
        $nextPath = [...$path, $productId];
        $materialCost = Money::zero();
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

            $componentBom = $this->activeBomForItem($item);
            if ($componentBom !== null) {
                $nested = $this->recalculateBom($componentBom, $nextPath);
                $unitCost = $nested['total_cost'];
                $costSource = 'bom_rollup';
                $warnings = [...$warnings, ...$nested['warnings']];
            } else {
                $unitCost = (string) $item->standard_cost;
                $costSource = 'standard_cost';
            }

            $extendedCost = Money::round2(bcmul($baseQuantity, $unitCost, 8));

            $line->forceFill([
                'cost_quantity' => bcadd($baseQuantity, '0', 6),
                'unit_cost'     => $unitCost,
                'extended_cost' => $extendedCost,
                'cost_source'   => $costSource,
            ])->save();

            if (bccomp($unitCost, '0', 4) === 0) {
                $warnings[] = [
                    'type'       => 'zero_standard_cost',
                    'item_code'  => $item->code,
                    'message'    => "Item {$item->code} has a zero standard cost.",
                ];
            }

            $materialCost = Money::add($materialCost, $extendedCost);
        }

        $conversionCosts = $this->routingCosts($productId);
        $totalCost = Money::add(
            $materialCost,
            $conversionCosts['labor_cost'],
            $conversionCosts['machine_cost'],
            $conversionCosts['overhead_cost'],
        );

        $bom->forceFill([
            'material_cost' => $materialCost,
            'labor_cost'    => $conversionCosts['labor_cost'],
            'machine_cost'  => $conversionCosts['machine_cost'],
            'overhead_cost' => $conversionCosts['overhead_cost'],
            'total_cost'    => $totalCost,
            'cost_basis'    => $conversionCosts['has_routing'] ? 'standard_cost+routing' : 'standard_cost',
            'costed_at'     => now(),
            'cost_warnings' => $warnings,
        ])->save();

        return [
            'material_cost' => $materialCost,
            'labor_cost'    => $conversionCosts['labor_cost'],
            'machine_cost'  => $conversionCosts['machine_cost'],
            'overhead_cost' => $conversionCosts['overhead_cost'],
            'total_cost'    => $totalCost,
            'warnings'      => $warnings,
        ];
    }

    private function activeBomForItem(Item $item): ?Bom
    {
        return Product::query()
            ->where('part_number', (string) $item->code)
            ->active()
            ->with('activeBom')
            ->first()?->activeBom;
    }

    /**
     * Calculate per-unit conversion costs from the product's active routing.
     * Cycle time is the per-unit time; setup time needs a batch-size policy and
     * is therefore not silently charged to every unit.
     *
     * @return array{labor_cost:string, machine_cost:string, overhead_cost:string, has_routing:bool}
     */
    private function routingCosts(int $productId): array
    {
        $routing = ProductRouting::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->with('operations')
            ->first();

        $costs = [
            'labor_cost' => Money::zero(),
            'machine_cost' => Money::zero(),
            'overhead_cost' => Money::zero(),
            'has_routing' => $routing !== null,
        ];

        if ($routing === null) {
            return $costs;
        }

        foreach ($routing->operations as $operation) {
            $hours = bcdiv((string) $operation->cycle_time_minutes, '60', 8);
            $costs['labor_cost'] = Money::add(
                $costs['labor_cost'],
                bcmul($hours, (string) $operation->labor_rate_per_hour, 8),
            );
            $costs['machine_cost'] = Money::add(
                $costs['machine_cost'],
                bcmul($hours, (string) $operation->machine_rate_per_hour, 8),
            );
            $costs['overhead_cost'] = Money::add(
                $costs['overhead_cost'],
                bcmul($hours, (string) $operation->overhead_rate_per_hour, 8),
            );
        }

        return $costs;
    }
}
