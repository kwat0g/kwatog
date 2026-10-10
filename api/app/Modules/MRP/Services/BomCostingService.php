<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\Money;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
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
 * time and hourly rates. Setup time is allocated across the configured cost
 * batch size before the per-unit BOM cost is frozen.
 */
class BomCostingService
{
    public function __construct(
        private readonly BomComponentIntegrityService $integrity,
        private readonly BomManufacturedComponentResolver $manufacturedComponents,
    ) {}

    public function recalculate(Bom $bom): Bom
    {
        $this->manufacturedComponents->reset();

        return DB::transaction(function () use ($bom): Bom {
            Product::query()->whereKey($bom->product_id)->lockForUpdate()->firstOrFail();
            $bom->refresh();
            if (! $bom->is_active || $bom->trashed()) {
                throw new BusinessRuleException('Historical BOM cost snapshots cannot be recalculated. Publish a new version instead.');
            }
            $this->invalidateForItems(Item::query()->where('code', Product::query()->whereKey($bom->product_id)->value('part_number'))->pluck('id')->all());
            $this->recalculateBom($bom, []);
            app(\App\Modules\CRM\Services\PricingReviewService::class)->request([(int) $bom->product_id], 'bom_or_routing_changed');

            return $bom->fresh()->load(['product', 'items.item']);
        }, 3);
    }

    /**
     * Rebuild a BOM snapshot when a component or nested BOM changed after it
     * was costed. This keeps planning runs from consuming stale money data.
     */
    public function ensureFresh(Bom $bom): Bom
    {
        return DB::transaction(function () use ($bom): Bom {
            Product::query()->whereKey($bom->product_id)->lockForUpdate()->firstOrFail();
            $this->manufacturedComponents->reset();
            $this->ensureFreshBom($bom, []);

            return $bom->fresh()->load(['product', 'items.item']);
        }, 3);
    }

    /** Invalidate current estimates only; historical BOM costs remain frozen. */
    public function invalidateForItems(array $itemIds): void
    {
        $visited = [];
        $products = [];
        while ($itemIds !== []) {
            $boms = Bom::query()->active()
                ->whereHas('items', fn ($query) => $query->whereIn('item_id', $itemIds))
                ->whereNotIn('id', $visited)->get(['id', 'product_id']);
            if ($boms->isEmpty()) {
                break;
            }
            $products = [...$products, ...$boms->pluck('product_id')->all()];
            $ids = $boms->pluck('id')->all();
            Bom::query()->active()->whereIn('id', $ids)->update(['costed_at' => null]);
            $visited = [...$visited, ...$ids];
            $codes = Product::query()->whereIn('id', $boms->pluck('product_id'))->pluck('part_number');
            $itemIds = Item::query()->whereIn('code', $codes)->pluck('id')->all();
        }
        app(\App\Modules\CRM\Services\PricingReviewService::class)->request($products, 'component_cost_or_conversion_changed');
    }

    /** @param list<int> $path */
    private function ensureFreshBom(Bom $bom, array $path): bool
    {
        $productId = (int) $bom->product_id;
        if (in_array($productId, $path, true)) {
            return false;
        }

        $bom->refresh();
        if (! $bom->is_active || $bom->trashed()) {
            return false;
        }
        $costedAt = $bom->costed_at;
        $stale = $costedAt === null;
        $lines = BomItem::query()
            ->where('bom_id', $bom->id)
            ->get(['item_id', 'updated_at', 'cost_source']);
        $items = Item::withTrashed()->whereIn('id', $lines->pluck('item_id'))->get()->keyBy('id');

        foreach ($lines as $line) {
            $item = $items->get((int) $line->item_id);
            if (
                $item === null
                || $costedAt === null
                || $line->updated_at?->gt($costedAt)
                || $item->updated_at?->gt($costedAt)
            ) {
                $stale = true;

                continue;
            }

            $nested = $this->activeBomForItem($item);
            if ($nested === null) {
                if ($line->cost_source === 'bom_rollup') {
                    $stale = true;
                }

                continue;
            }

            $nestedChanged = $this->ensureFreshBom($nested, [...$path, $productId]);
            $nested->refresh();
            if ($nestedChanged || $costedAt === null || $nested->updated_at?->gt($costedAt)) {
                $stale = true;
            }
        }

        if ($stale) {
            $this->recalculateBom($bom, []);
        }

        return $stale;
    }

    /**
     * @param  list<int>  $path
     * @return array{material_cost:string, labor_cost:string, machine_cost:string, overhead_cost:string, total_cost:string, warnings:list<array<string, string>>}
     */
    private function recalculateBom(Bom $bom, array $path): array
    {
        $locked = Bom::withTrashed()->whereKey($bom->id)->lockForUpdate()->firstOrFail();
        if (! $locked->is_active || $locked->trashed()) {
            throw new BusinessRuleException('Historical BOM cost snapshots cannot be recalculated. Publish a new version instead.');
        }
        $bom->refresh();
        $this->integrity->assertValid($bom);
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
                'unit_cost' => $unitCost,
                'extended_cost' => $extendedCost,
                'cost_source' => $costSource,
            ])->save();

            if (bccomp($unitCost, '0', 4) === 0) {
                $warnings[] = [
                    'type' => 'zero_standard_cost',
                    'item_code' => $item->code,
                    'message' => "Item {$item->code} has a zero standard cost.",
                ];
            }

            $materialCost = Money::add($materialCost, $extendedCost);
        }

        $conversionCosts = $this->routingCosts($productId, $bom);
        $warnings = [...$warnings, ...$conversionCosts['warnings']];
        $totalCost = Money::add(
            $materialCost,
            $conversionCosts['labor_cost'],
            $conversionCosts['machine_cost'],
            $conversionCosts['overhead_cost'],
        );

        $bom->forceFill([
            'material_cost' => $materialCost,
            'labor_cost' => $conversionCosts['labor_cost'],
            'machine_cost' => $conversionCosts['machine_cost'],
            'overhead_cost' => $conversionCosts['overhead_cost'],
            'total_cost' => $totalCost,
            'cost_basis' => $conversionCosts['has_routing'] ? 'standard_cost+routing' : 'standard_cost',
            'costed_at' => now(),
            'cost_warnings' => $warnings,
        ])->save();

        return [
            'material_cost' => $materialCost,
            'labor_cost' => $conversionCosts['labor_cost'],
            'machine_cost' => $conversionCosts['machine_cost'],
            'overhead_cost' => $conversionCosts['overhead_cost'],
            'total_cost' => $totalCost,
            'warnings' => $warnings,
        ];
    }

    private function activeBomForItem(Item $item): ?Bom
    {
        return $this->manufacturedComponents->forItem($item);
    }

    /**
     * Calculate per-unit conversion costs from the product's active routing.
     * Cycle time is the per-unit time; setup time needs a batch-size policy and
     * is therefore not silently charged to every unit.
     *
     * @return array{labor_cost:string, machine_cost:string, overhead_cost:string, has_routing:bool, warnings:list<array<string, string>>}
     */
    private function routingCosts(int $productId, Bom $bom): array
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
            'warnings' => [],
        ];

        if ($routing === null) {
            $costs['warnings'][] = ['type' => 'missing_routing', 'item_code' => '', 'message' => 'Materials only: no active routing supplies labor, machine or overhead costs.'];
            return $costs;
        }

        if ($routing->operations->isEmpty()) {
            $costs['warnings'][] = ['type' => 'empty_routing', 'item_code' => '', 'message' => 'The active routing has no operations; conversion costs need review.'];
        }

        foreach ($routing->operations as $operation) {
            if (bccomp((string) $operation->labor_rate_per_hour, '0', 4) <= 0
                || bccomp((string) $operation->overhead_rate_per_hour, '0', 4) <= 0
                || ($operation->machine_id !== null && bccomp((string) $operation->machine_rate_per_hour, '0', 4) <= 0)) {
                $costs['warnings'][] = ['type' => 'missing_operation_rates', 'item_code' => '', 'message' => "Operation {$operation->operation_name} has zero labor, overhead or assigned-machine rates; review cost completeness."];
            }
            if (bccomp((string) $operation->cycle_time_minutes, '0', 2) <= 0
                && bccomp((string) $operation->setup_time_minutes, '0', 2) <= 0) {
                $costs['warnings'][] = ['type' => 'missing_operation_time', 'item_code' => '', 'message' => "Operation {$operation->operation_name} has no cycle or setup time; conversion costs need review."];
            }
            $hours = bcdiv((string) $operation->cycle_time_minutes, '60', 8);
            $setupHours = bcdiv((string) $operation->setup_time_minutes, '60', 8);
            $batchSize = max('1.000', (string) $bom->cost_batch_size);
            $allocatedSetupHours = bcdiv($setupHours, $batchSize, 8);
            $costs['labor_cost'] = Money::add(
                $costs['labor_cost'],
                bcadd(
                    bcmul($hours, (string) $operation->labor_rate_per_hour, 8),
                    bcmul($allocatedSetupHours, (string) $operation->labor_rate_per_hour, 8),
                    8,
                ),
            );
            $costs['machine_cost'] = Money::add(
                $costs['machine_cost'],
                bcadd(
                    bcmul($hours, (string) $operation->machine_rate_per_hour, 8),
                    bcmul($allocatedSetupHours, (string) $operation->machine_rate_per_hour, 8),
                    8,
                ),
            );
            $costs['overhead_cost'] = Money::add(
                $costs['overhead_cost'],
                bcadd(
                    bcmul($hours, (string) $operation->overhead_rate_per_hour, 8),
                    bcmul($allocatedSetupHours, (string) $operation->overhead_rate_per_hour, 8),
                    8,
                ),
            );
        }

        return $costs;
    }
}
