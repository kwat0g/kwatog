<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ApprovedSupplierService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = ApprovedSupplier::query()->with(['item:id,code,name', 'vendor:id,name']);
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
        return $q->orderByDesc('is_preferred')->orderBy('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
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
                    'lead_time_days' => $data['lead_time_days'] ?? 0,
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
