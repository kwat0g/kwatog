<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\SupplierListingStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\SupplierItemListing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierListingService
{
    /**
     * Read-only Ogami item catalog shown to suppliers in the portal so they
     * can anchor their offers to real items. Active, purchasable items only.
     */
    public function catalog(): Collection
    {
        return Item::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'unit_of_measure']);
    }

    public function listForVendor(int $vendorId, array $filters): LengthAwarePaginator
    {
        $q = SupplierItemListing::query()
            ->with(['item:id,code,name,unit_of_measure'])
            ->where('vendor_id', $vendorId);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return $q->orderByDesc('submitted_at')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function listForReview(array $filters): LengthAwarePaginator
    {
        $q = SupplierItemListing::query()
            ->with(['item:id,code,name,unit_of_measure', 'vendor:id,name', 'reviewer:id,name']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $q->where(function ($w) use ($search) {
                $w->whereHas('vendor', fn ($v) => $v->where('name', 'ilike', "%{$search}%"))
                    ->orWhereHas('item', fn ($i) => $i->where('code', 'ilike', "%{$search}%")
                        ->orWhere('name', 'ilike', "%{$search}%"))
                    ->orWhere('supplier_item_code', 'ilike', "%{$search}%");
            });
        }

        return $q->orderByDesc('submitted_at')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    /**
     * Supplier submits an offer anchored to an Ogami item. Lands as pending;
     * nothing touches approved_suppliers until Purchasing approves it.
     */
    public function submit(int $vendorId, array $data): SupplierItemListing
    {
        return DB::transaction(function () use ($vendorId, $data) {
            $itemId = $data['item_id'];
            unset($data['item_id']);

            $existing = SupplierItemListing::query()
                ->where('vendor_id', $vendorId)
                ->where('item_id', $itemId)
                ->pending()
                ->lockForUpdate()
                ->exists();
            if ($existing) {
                throw new BusinessRuleException('A pending listing already exists for this item. Update it instead of submitting again.');
            }

            $listing = new SupplierItemListing($data);
            $listing->vendor_id = $vendorId;
            $listing->item_id = $itemId;
            $listing->status = SupplierListingStatus::Pending;
            $listing->submitted_at = now();
            $listing->save();

            $this->notifyReviewers($listing);

            return $listing->load(['item:id,code,name,unit_of_measure']);
        });
    }

    /**
     * Suppliers may refine a submission only while it is still pending.
     */
    public function update(SupplierItemListing $listing, array $data): SupplierItemListing
    {
        if ($listing->status !== SupplierListingStatus::Pending) {
            throw new BusinessRuleException('Only pending listings can be updated. Submit a new listing instead.');
        }

        return DB::transaction(function () use ($listing, $data) {
            $listing->fill($data);
            $listing->save();

            return $listing->fresh(['item:id,code,name,unit_of_measure']);
        });
    }

    /**
     * Approve: supersede the vendor's previous approved listing for the same
     * item, then sync the offer into approved_suppliers. last_price is stored
     * per BASE unit (the unit MRP, auto-PO and PO lines all price in); the
     * supplier's quoted basis is kept alongside for display.
     */
    public function approve(SupplierItemListing $listing, User $reviewer): SupplierItemListing
    {
        if ($listing->status !== SupplierListingStatus::Pending) {
            throw new BusinessRuleException('Only pending listings can be approved.');
        }

        return DB::transaction(function () use ($listing, $reviewer) {
            SupplierItemListing::query()
                ->where('vendor_id', $listing->vendor_id)
                ->where('item_id', $listing->item_id)
                ->where('status', SupplierListingStatus::Approved->value)
                ->where('id', '!=', $listing->id)
                ->update(['status' => SupplierListingStatus::Superseded->value]);

            $listing->forceFill([
                'status' => SupplierListingStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            $row = ApprovedSupplier::firstOrNew([
                'item_id' => $listing->item_id,
                'vendor_id' => $listing->vendor_id,
            ]);
            $row->fill([
                'last_price' => $this->pricePerBaseUnit($listing),
                'last_price_at' => now(),
                'lead_time_days' => $listing->lead_time_days,
                'supplier_item_code' => $listing->supplier_item_code,
                'supplier_item_name' => $listing->supplier_item_name,
                'order_uom' => $listing->order_uom,
                'base_qty_per_order_unit' => $listing->base_qty_per_order_unit,
                'price_valid_until' => $listing->valid_until,
                'supplier_listing_id' => $listing->id,
            ]);
            $row->save();

            return $listing->load(['item:id,code,name,unit_of_measure', 'vendor:id,name']);
        });
    }

    public function reject(SupplierItemListing $listing, User $reviewer, string $reason): SupplierItemListing
    {
        if ($listing->status !== SupplierListingStatus::Pending) {
            throw new BusinessRuleException('Only pending listings can be rejected.');
        }

        return DB::transaction(function () use ($listing, $reviewer, $reason) {
            $listing->forceFill([
                'status' => SupplierListingStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            return $listing->load(['item:id,code,name,unit_of_measure', 'vendor:id,name']);
        });
    }

    private function pricePerBaseUnit(SupplierItemListing $listing): string
    {
        $qty = $listing->base_qty_per_order_unit;
        if ($qty === null || Money::isZero($qty)) {
            return Money::round2($listing->price);
        }

        return Money::round2(Money::div($listing->price, $qty, 6));
    }

    private function notifyReviewers(SupplierItemListing $listing): void
    {
        try {
            $item = $listing->item;
            $vendor = $listing->vendor;
            $reviewers = User::query()
                ->where('is_active', true)
                ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'purchasing.supplier_listings.review'))
                ->get();

            app(NotificationService::class)->send($reviewers, 'supplier_listing_submitted', [
                'title' => 'Supplier listing awaiting review',
                'message' => "{$vendor?->name} submitted an item listing for {$item?->code}.",
                'link_to' => '/purchasing/supplier-listings',
            ]);
        } catch (\Throwable $e) {
            Log::warning('supplier_listing notification failed: '.$e->getMessage());
        }
    }
}
