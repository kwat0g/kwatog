<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Support\HashIdFilter;
use App\Common\Support\TrashedFilter;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ApprovedSupplierService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = ApprovedSupplier::query()->with(['item:id,code,name', 'vendor:id,name']);
        TrashedFilter::apply($q, $filters);
        if (! empty($filters['item_id'])) {
            $iid = HashIdFilter::decode($filters['item_id'], Item::class);
            if ($iid) $q->where('item_id', $iid);
        }
        if (! empty($filters['vendor_id'])) {
            $vid = HashIdFilter::decode($filters['vendor_id'], Vendor::class);
            if ($vid) $q->where('vendor_id', $vid);
        }
        if (isset($filters['is_preferred']) && $filters['is_preferred'] !== '') {
            $q->where('is_preferred', filter_var($filters['is_preferred'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['qualification_status'])) {
            $q->where('qualification_status', $filters['qualification_status']);
        }
        // The list page has always rendered a search box; the service ignored
        // it, so typing did nothing (read as broken). Match the columns the
        // table shows: item code/name, vendor name, and the supplier's own part
        // number/description.
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $q->where(function (Builder $sub) use ($term): void {
                $sub->where('supplier_item_code', 'ilike', $term)
                    ->orWhere('supplier_item_name', 'ilike', $term)
                    ->orWhereHas('item', fn (Builder $item) => $item
                        ->where('code', 'ilike', $term)
                        ->orWhere('name', 'ilike', $term))
                    ->orWhereHas('vendor', fn (Builder $vendor) => $vendor->where('name', 'ilike', $term));
            });
        }
        return $q->orderByDesc('is_preferred')->orderBy('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    /**
     * Filter dropdown data. Scoped to values that actually appear on a live
     * link so the pickers cannot offer a vendor/item that yields an empty list,
     * and bounded by the ASL rather than the full item/vendor catalogues.
     *
     * @return array{items: array<int, array{value: string, label: string}>, vendors: array<int, array{value: string, label: string}>, qualification_statuses: array<int, array{value: string, label: string}>}
     */
    public function options(): array
    {
        $liveItemIds = ApprovedSupplier::query()->select('item_id');
        $liveVendorIds = ApprovedSupplier::query()->select('vendor_id');

        return [
            'items' => Item::query()
                ->whereIn('id', $liveItemIds)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Item $item): array => [
                    'value' => $item->hash_id,
                    'label' => $item->code.' — '.$item->name,
                ])->all(),
            'vendors' => Vendor::query()
                ->whereIn('id', $liveVendorIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Vendor $vendor): array => [
                    'value' => $vendor->hash_id,
                    'label' => $vendor->name,
                ])->all(),
            'qualification_statuses' => [
                ['value' => ApprovedSupplier::QUALIFICATION_APPROVED, 'label' => 'Approved'],
                ['value' => ApprovedSupplier::QUALIFICATION_PROVISIONAL, 'label' => 'Provisional'],
            ],
        ];
    }

    public function create(array $data): ApprovedSupplier
    {
        return DB::transaction(function () use ($data) {
            $itemId = HashIdFilter::decode($data['item_id'], Item::class) ?? (int) $data['item_id'];
            $vendorId = HashIdFilter::decode($data['vendor_id'], Vendor::class) ?? (int) $data['vendor_id'];
            $row = ApprovedSupplier::firstOrCreate(
                ['item_id' => $itemId, 'vendor_id' => $vendorId],
                [
                    'is_preferred'   => $data['is_preferred'] ?? false,
                    'qualification_status' => ApprovedSupplier::QUALIFICATION_APPROVED,
                    'lead_time_days' => $data['lead_time_days'] ?? null,
                    'last_price'     => $data['last_price'] ?? null,
                ]
            );
            if (! empty($data['is_preferred'])) {
                $this->setPreferred($row);
            }
            return $row->fresh();
        });
    }

    public function update(ApprovedSupplier $row, array $data): ApprovedSupplier
    {
        return DB::transaction(function () use ($row, $data) {
            $row->update([
                'lead_time_days' => $data['lead_time_days'] ?? $row->lead_time_days,
                'last_price'     => $data['last_price']     ?? $row->last_price,
            ]);
            if (isset($data['is_preferred']) && $data['is_preferred']) {
                $this->setPreferred($row);
            } elseif (isset($data['is_preferred']) && ! $data['is_preferred']) {
                $row->update(['is_preferred' => false]);
            }
            return $row->fresh();
        });
    }

    public function delete(ApprovedSupplier $row): void
    {
        $row->delete();
    }

    private function setPreferred(ApprovedSupplier $row): void
    {
        // Only one preferred per item.
        ApprovedSupplier::query()
            ->where('item_id', $row->item_id)
            ->where('id', '!=', $row->id)
            ->update(['is_preferred' => false]);
        $row->update(['is_preferred' => true]);
    }
}
