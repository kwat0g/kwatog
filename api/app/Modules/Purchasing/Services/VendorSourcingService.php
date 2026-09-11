<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Support\Money;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\SupplierListingStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\SupplierItemListing;

/**
 * One source of truth for "which vendor can supply this item, and at what price".
 *
 * Before this service the answer depended on the caller: PR submit looked only
 * for a *preferred* approved supplier (so an item with an approved-but-not-
 * preferred supplier or a quoted listing silently fell to manual conversion),
 * the critical-stock auto-PO demanded exactly one preferred supplier, and the
 * manual modal offered every vendor with no guidance. Auto and manual drift
 * was structural, not incidental.
 *
 * Candidate tiers, best first:
 *   1. preferred qualified approved supplier
 *   2. any other qualified approved supplier (most recent price first)
 *   3. a vendor with an approved/pending supplier listing for the item
 *   4. a vendor that has actually supplied the item before (PO history)
 *
 * Tiers 1–2 are the only ones flagged `qualified`; 3–4 are suggestions that the
 * operator must confirm (a listing is a claim, a past PO is history — neither is
 * an ASL qualification).
 */
class VendorSourcingService
{
    public const TIER_PREFERRED = 'preferred';

    public const TIER_APPROVED = 'approved';

    public const TIER_LISTED = 'listed';

    public const TIER_HISTORY = 'history';

    /**
     * Ranked candidate vendors for an item.
     *
     * @return list<array{
     *     vendor_id: int,
     *     vendor_name: string|null,
     *     tier: string,
     *     qualified: bool,
     *     price: string|null,
     *     lead_time_days: int|null,
     *     order_uom: string|null,
     *     base_qty_per_order_unit: string|null
     * }>
     */
    public function candidatesForItem(int $itemId, ?int $limit = null): array
    {
        $candidates = [];
        $seen = [];

        // Tiers 1–2 — qualified ASL links.
        $approved = ApprovedSupplier::query()
            ->qualified()
            ->with('vendor:id,name')
            ->where('item_id', $itemId)
            ->orderByDesc('is_preferred')
            ->orderByDesc('last_price_at')
            ->orderBy('id')
            ->get();

        foreach ($approved as $row) {
            $vendorId = (int) $row->vendor_id;
            $candidates[] = [
                'vendor_id' => $vendorId,
                'vendor_name' => $row->vendor?->name,
                'tier' => $row->is_preferred ? self::TIER_PREFERRED : self::TIER_APPROVED,
                'qualified' => true,
                'price' => $this->positiveOrNull($row->last_price),
                'lead_time_days' => $row->lead_time_days !== null ? (int) $row->lead_time_days : null,
                'order_uom' => $row->order_uom,
                'base_qty_per_order_unit' => $row->base_qty_per_order_unit !== null
                    ? (string) $row->base_qty_per_order_unit
                    : null,
            ];
            $seen[$vendorId] = true;
        }

        // Tier 3 — supplier-listed items (approved first, then pending).
        $listings = SupplierItemListing::query()
            ->with('vendor:id,name')
            ->where('item_id', $itemId)
            ->whereIn('status', [
                SupplierListingStatus::Approved->value,
                SupplierListingStatus::Pending->value,
            ])
            ->orderByRaw("CASE WHEN status = 'approved' THEN 0 ELSE 1 END")
            ->orderByDesc('submitted_at')
            ->get();

        foreach ($listings as $listing) {
            $vendorId = (int) $listing->vendor_id;
            if (isset($seen[$vendorId])) {
                continue;
            }
            $candidates[] = [
                'vendor_id' => $vendorId,
                'vendor_name' => $listing->vendor?->name,
                'tier' => self::TIER_LISTED,
                'qualified' => false,
                'price' => $this->listingPricePerBaseUnit($listing),
                'lead_time_days' => (int) $listing->lead_time_days,
                'order_uom' => $listing->order_uom,
                'base_qty_per_order_unit' => $listing->base_qty_per_order_unit !== null
                    ? (string) $listing->base_qty_per_order_unit
                    : null,
            ];
            $seen[$vendorId] = true;
        }

        // Tier 4 — vendors that actually supplied the item before.
        $historyVendorIds = $this->historyVendorIds($itemId);
        if ($historyVendorIds !== []) {
            $names = Vendor::query()->whereIn('id', $historyVendorIds)->pluck('name', 'id');
            foreach ($historyVendorIds as $vendorId) {
                if (isset($seen[$vendorId])) {
                    continue;
                }
                $candidates[] = [
                    'vendor_id' => (int) $vendorId,
                    'vendor_name' => $names[$vendorId] ?? null,
                    'tier' => self::TIER_HISTORY,
                    'qualified' => false,
                    'price' => $this->lastPurchasePrice((int) $vendorId, $itemId),
                    'lead_time_days' => null,
                    'order_uom' => null,
                    'base_qty_per_order_unit' => null,
                ];
                $seen[$vendorId] = true;
            }
        }

        return $limit !== null ? array_slice($candidates, 0, $limit) : $candidates;
    }

    /** The single best vendor for an item, or null. */
    public function suggestVendorId(int $itemId): ?int
    {
        $best = $this->candidatesForItem($itemId, 1);

        return $best[0]['vendor_id'] ?? null;
    }

    /**
     * Authoritative per-base-unit price for an item/vendor pair, or null when no
     * positive price is known. Prefers the qualified ASL price, then a listing,
     * then the last purchase price.
     */
    public function priceFor(int $itemId, int $vendorId): ?string
    {
        $row = ApprovedSupplier::query()
            ->qualified()
            ->where('item_id', $itemId)
            ->where('vendor_id', $vendorId)
            ->first();
        $price = $this->positiveOrNull($row?->last_price);
        if ($price !== null) {
            return $price;
        }

        $listing = SupplierItemListing::query()
            ->where('item_id', $itemId)
            ->where('vendor_id', $vendorId)
            ->whereIn('status', [
                SupplierListingStatus::Approved->value,
                SupplierListingStatus::Pending->value,
            ])
            ->orderByRaw("CASE WHEN status = 'approved' THEN 0 ELSE 1 END")
            ->orderByDesc('submitted_at')
            ->first();
        if ($listing !== null) {
            $price = $this->listingPricePerBaseUnit($listing);
            if ($price !== null) {
                return $price;
            }
        }

        return $this->lastPurchasePrice($vendorId, $itemId);
    }

    /**
     * Resolve a vendor (and price) for every line of a PR.
     *
     * @param  iterable<object{id:int,item_id:int|null,suggested_vendor_id:int|null,estimated_unit_price:string|null}>  $lines
     * @return array{
     *     assignments: array<int, array{vendor_id:int, unit_price:string, source:string}>,
     *     unresolved: list<int>
     * }
     */
    public function resolveLines(iterable $lines): array
    {
        $assignments = [];
        $unresolved = [];

        foreach ($lines as $line) {
            $lineId = (int) $line->id;
            $itemId = $line->item_id !== null ? (int) $line->item_id : null;
            $vendorId = $line->suggested_vendor_id !== null ? (int) $line->suggested_vendor_id : null;

            if ($itemId && $vendorId === null) {
                $vendorId = $this->suggestVendorId($itemId);
            }
            if ($itemId === null || $vendorId === null) {
                $unresolved[] = $lineId;
                continue;
            }

            $price = $this->priceFor($itemId, $vendorId);
            $source = 'supplier';
            if ($price === null) {
                $estimate = $this->positiveOrNull($line->estimated_unit_price);
                if ($estimate !== null) {
                    $price = $estimate;
                    $source = 'estimate';
                }
            }
            if ($price === null) {
                $unresolved[] = $lineId;
                continue;
            }

            $assignments[$lineId] = [
                'vendor_id' => $vendorId,
                'unit_price' => $price,
                'source' => $source,
            ];
        }

        return ['assignments' => $assignments, 'unresolved' => $unresolved];
    }

    /**
     * Sourcing payload for the conversion UI: every PR line with its suggested
     * vendor/price and the ranked candidates to choose from.
     *
     * @return list<array<string, mixed>>
     */
    public function sourcingLines(PurchaseRequest $pr): array
    {
        $pr->loadMissing(['items.item', 'items.suggestedVendor:id,name']);

        $out = [];
        foreach ($pr->items as $line) {
            $itemId = $line->item_id !== null ? (int) $line->item_id : null;
            $candidates = $itemId ? $this->candidatesForItem($itemId) : [];

            $suggestedVendorId = $line->suggested_vendor_id !== null
                ? (int) $line->suggested_vendor_id
                : ($candidates[0]['vendor_id'] ?? null);

            $suggestedPrice = null;
            if ($itemId && $suggestedVendorId) {
                $suggestedPrice = $this->priceFor($itemId, (int) $suggestedVendorId);
            }
            $estimate = $this->positiveOrNull($line->estimated_unit_price);

            $out[] = [
                'id' => $line->hash_id,
                'item' => $line->item ? [
                    'id' => $line->item->hash_id,
                    'code' => $line->item->code,
                    'name' => $line->item->name,
                ] : null,
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit' => $line->unit,
                'estimated_unit_price' => $line->estimated_unit_price !== null ? (string) $line->estimated_unit_price : null,
                'estimated_total' => $line->estimated_total,
                'suggested_vendor_id' => $suggestedVendorId ? app('hashids')->encode((int) $suggestedVendorId) : null,
                'suggested_unit_price' => $suggestedPrice ?? $estimate,
                'candidates' => array_map(function (array $c): array {
                    return [
                        'id' => app('hashids')->encode((int) $c['vendor_id']),
                        'name' => $c['vendor_name'],
                        'tier' => $c['tier'],
                        'qualified' => $c['qualified'],
                        'unit_price' => $c['price'],
                        'lead_time_days' => $c['lead_time_days'],
                        'order_uom' => $c['order_uom'],
                        'base_qty_per_order_unit' => $c['base_qty_per_order_unit'],
                    ];
                }, $candidates),
            ];
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function historyVendorIds(int $itemId): array
    {
        return PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_order_items.item_id', $itemId)
            ->where('purchase_orders.status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->whereNull('purchase_orders.deleted_at')
            ->orderByDesc('purchase_orders.date')
            ->orderByDesc('purchase_orders.id')
            ->pluck('purchase_orders.vendor_id')
            ->unique()
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function lastPurchasePrice(int $vendorId, int $itemId): ?string
    {
        $price = PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_order_items.item_id', $itemId)
            ->where('purchase_orders.vendor_id', $vendorId)
            ->where('purchase_orders.status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->whereNull('purchase_orders.deleted_at')
            ->orderByDesc('purchase_orders.date')
            ->orderByDesc('purchase_orders.id')
            ->value('purchase_order_items.unit_price');

        return $this->positiveOrNull($price);
    }

    private function listingPricePerBaseUnit(SupplierItemListing $listing): ?string
    {
        $price = $listing->price;
        if ($price === null) {
            return null;
        }

        $qty = $listing->base_qty_per_order_unit;
        if ($qty === null || Money::isZero($qty)) {
            return $this->positiveOrNull($price);
        }

        return $this->positiveOrNull(Money::div($price, $qty, 6));
    }

    private function positiveOrNull(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Money::gt((string) $value, '0') ? Money::round2((string) $value) : null;
    }
}
