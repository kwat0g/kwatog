<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Models\ShipmentLandedCost;
use App\Modules\SupplyChain\Enums\LandedCostAllocationMethod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * OGAMI-104 — Landed cost calculation for inbound shipments.
 *
 * Computes the additional costs (freight, insurance, duties, brokerage,
 * other) incurred to bring goods into the warehouse and allocates them
 * across the shipment's PO lines using a chosen allocation method.
 */
class LandedCostService
{
    /**
     * Relations every returned shipment must carry.
     *
     * `landedCosts.shipment` is not decoration. `ShipmentLandedCostResource`
     * reads `$this->shipment?->hash_id`, and `AppServiceProvider` sets
     * `Model::preventLazyLoading(! isProduction())` — so omitting it made
     * `POST /shipments/{id}/calculate-landed-cost` throw
     * `LazyLoadingViolationException` (a 500) for every shipment with more than
     * one PO line outside production, and a silent N+1 inside it. No test posted
     * to that route, so the only landed-cost endpoint in the module was broken
     * on every real (multi-line) import.
     */
    private const ALLOCATION_RELATIONS = [
        'landedCosts.purchaseOrderItem',
        'landedCosts.shipment',
    ];

    /**
     * Calculate and persist landed cost allocations for a shipment.
     *
     * @param Shipment $shipment     The inbound shipment.
     * @param string|null $method    Allocation method:
     *                               'by_value' (default), 'by_weight',
     *                               'by_quantity', or 'manual'.
     * @return Shipment              Freshly-loaded shipment with landedCosts.
     */
    public function calculate(Shipment $shipment, ?string $method = null): Shipment
    {
        $method ??= $shipment->allocation_method ?? LandedCostAllocationMethod::ByValue->value;
        if (! in_array($method, LandedCostAllocationMethod::values(), true)) {
            throw new InvalidArgumentException("Invalid allocation method: {$method}");
        }
        $this->assertMethodIsSupported($method);

        return DB::transaction(function () use ($shipment, $method) {
            $shipment->load([
                'purchaseOrder.items.item',
                'landedCosts',
            ]);

            $poItems = $shipment->purchaseOrder?->items;
            if (! $poItems || $poItems->isEmpty()) {
                throw new BusinessRuleException('Shipment has no purchase order items to allocate costs against.');
            }

            $freightCost   = (float) ($shipment->freight_cost ?? 0);
            $insuranceCost = (float) ($shipment->insurance_cost ?? 0);
            $dutiesAmount  = (float) ($shipment->duties_amount ?? 0);
            $brokerageFee  = (float) ($shipment->brokerage_fee ?? 0);
            $otherCharges  = (float) ($shipment->other_charges ?? 0);

            $totalAddCosts = $freightCost + $insuranceCost + $dutiesAmount + $brokerageFee + $otherCharges;

            // Handle edge case: no additional costs — zero-out allocations.
            if ($totalAddCosts <= 0) {
                $this->zeroAllocations($shipment, $poItems);
                $shipment->forceFill([
                    'landed_cost_total'         => '0.00',
                    'allocation_method'         => $method,
                    'landed_cost_calculated_at' => now(),
                ])->save();

                return $shipment->fresh()->load(self::ALLOCATION_RELATIONS);
            }

            $ratios = $this->computeRatios($poItems, $method);

            // Delete existing allocations and re-insert fresh ones.
            $shipment->landedCosts()->delete();

            foreach ($poItems as $i => $item) {
                $ratio = $ratios[$i] ?? 0;

                $af = round($freightCost * $ratio, 2);
                $ai = round($insuranceCost * $ratio, 2);
                $ad = round($dutiesAmount * $ratio, 2);
                $ab = round($brokerageFee * $ratio, 2);
                $ao = round($otherCharges * $ratio, 2);
                $ta = $af + $ai + $ad + $ab + $ao;

                ShipmentLandedCost::create([
                    'shipment_id'           => $shipment->id,
                    'purchase_order_item_id' => $item->id,
                    'allocated_freight'     => $af,
                    'allocated_insurance'   => $ai,
                    'allocated_duties'      => $ad,
                    'allocated_brokerage'   => $ab,
                    'allocated_other'       => $ao,
                    'total_allocated'       => $ta,
                ]);
            }

            $shipment->forceFill([
                'landed_cost_total'         => (string) round($totalAddCosts, 2),
                'allocation_method'         => $method,
                'landed_cost_calculated_at' => now(),
            ])->save();

            return $shipment->fresh()->load(self::ALLOCATION_RELATIONS);
        });
    }

    /**
     * Re-run landed cost calculation using the shipment's current method.
     * Convenience wrapper for when cost fields change after initial calculation.
     */
    public function recalculate(Shipment $shipment): Shipment
    {
        $method = $shipment->allocation_method ?? LandedCostAllocationMethod::ByValue->value;
        return $this->calculate($shipment, $method);
    }

    /**
     * Get landed cost allocations for all shipment lines linked to a given PO.
     *
     * @return Collection<int, ShipmentLandedCost>
     */
    public function getPoLineLandedCost(PurchaseOrder $po): Collection
    {
        $poItemIds = $po->items()->pluck('id');

        return ShipmentLandedCost::query()
            ->whereIn('purchase_order_item_id', $poItemIds)
            ->with(['shipment', 'purchaseOrderItem'])
            ->get();
    }

    // ─────────────────────── private helpers ───────────────────────

    /**
     * Compute allocation ratios for each PO line based on the method.
     *
     * @param Collection<int, PurchaseOrderItem> $poItems
     * @return float[]  Ratios summing to 1.0 (or 0 for empty totals).
     */
    private function computeRatios(Collection $poItems, string $method): array
    {
        if ($method === 'manual') {
            // Manual allocation: equal split (user enters amounts directly).
            $count = $poItems->count();
            $ratio = $count > 0 ? 1.0 / $count : 0;
            return array_fill(0, $count, $ratio);
        }

        $denominators = match ($method) {
            'by_value'   => $poItems->map(fn (PurchaseOrderItem $i) => (float) ($i->total ?? 0)),
            'by_quantity' => $poItems->map(fn (PurchaseOrderItem $i) => (float) ($i->quantity ?? 0)),
            'by_weight'   => $this->getItemWeights($poItems),
            default       => throw new InvalidArgumentException("Unknown method: {$method}"),
        };

        $total = $denominators->sum();
        if ($total <= 0) {
            // Fall back to equal split if there's no weight/value/quantity data.
            $count = $poItems->count();
            $equal = $count > 0 ? 1.0 / $count : 0;
            return array_fill(0, $count, $equal);
        }

        return $denominators->map(fn (float $d) => $d / $total)->values()->all();
    }

    /**
     * Extract net_weight from each PO line's item (or 0 if not available).
     *
     * Returns a base collection, NOT an Eloquent one: `Eloquent\Collection::map()`
     * downgrades to `Support\Collection` the moment the mapped values are not
     * Models, so the old `: Eloquent\Collection` return type here was violated on
     * EVERY call and raised a `TypeError` — which, not being a
     * `BusinessRuleException`, escaped the whole `DB::transaction()` closure as an
     * unhandled 500. `by_weight` had therefore never once completed.
     *
     * @param Collection<int, PurchaseOrderItem> $poItems
     * @return SupportCollection<int, float>
     */
    private function getItemWeights(Collection $poItems): SupportCollection
    {
        return $poItems->map(function (PurchaseOrderItem $item) {
            $item->loadMissing('item');
            $weight = (float) ($item->item->net_weight ?? $item->item->weight ?? 0);
            // Scale weight by ordered quantity for proportional allocation.
            // Purchase-order quantity is required for new lines. Treat an
            // incomplete legacy line as zero rather than inventing one unit
            // and skewing landed-cost allocation.
            return $weight * (float) ($item->quantity ?? 0);
        })->toBase();
    }

    /**
     * `by_weight` cannot be honoured: `items` has no weight column at all
     * (verified against `information_schema` — zero `%weight%` columns), so
     * `getItemWeights()` can only ever return zeros, and `computeRatios()` would
     * then fall through to an EQUAL SPLIT while reporting `allocation_method =
     * by_weight`. Silently apportioning by line count under a name that promises
     * weight is worse than refusing.
     *
     * Refusing is deliberately not the same as capturing item weights and
     * apportioning by them — that is a scoped change to the allocation basis and
     * belongs with the rest of the landed-cost work (action plan tranche B).
     */
    private function assertMethodIsSupported(string $method): void
    {
        if ($method !== LandedCostAllocationMethod::ByWeight->value) {
            return;
        }

        throw new BusinessRuleException(
            'Landed cost cannot be allocated by weight: purchase-order items carry no '
            .'weight, so every line would weigh zero and the charge would be split equally '
            .'instead. Choose by value, by quantity, or manual.'
        );
    }

    /**
     * Zero out allocations for all PO lines (used when total costs are zero).
     */
    private function zeroAllocations(Shipment $shipment, Collection $poItems): void
    {
        $shipment->landedCosts()->delete();

        foreach ($poItems as $item) {
            ShipmentLandedCost::create([
                'shipment_id'           => $shipment->id,
                'purchase_order_item_id' => $item->id,
                'allocated_freight'     => 0,
                'allocated_insurance'   => 0,
                'allocated_duties'      => 0,
                'allocated_brokerage'   => 0,
                'allocated_other'       => 0,
                'total_allocated'       => 0,
            ]);
        }
    }
}
