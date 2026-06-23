<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Support\SearchOperator;

use App\Modules\Accounting\Models\Vendor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VendorService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = Vendor::query();

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('name', SearchOperator::like(), "%{$term}%")
                   ->orWhere('contact_person', SearchOperator::like(), "%{$term}%");
            });
        }

        return $q->orderBy('name')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Vendor $vendor): Vendor
    {
        return $vendor->loadCount(['bills']);
    }

    public function create(array $data): Vendor
    {
        // OGAMI audit DEFECT-2 — record the vendor's creator so the PO
        // vendor-creator SoD guard (PurchaseOrderService::assertVendorSod) can
        // fire. Caller-supplied created_by wins (seeders/imports); otherwise the
        // authenticated web user. Only set when the column exists, so this stays
        // safe if the migration has not run yet.
        if (\Illuminate\Support\Facades\Schema::hasColumn('vendors', 'created_by')) {
            $data['created_by'] ??= Auth::id();
        }

        return DB::transaction(fn () => Vendor::create($data));
    }

    public function update(Vendor $vendor, array $data): Vendor
    {
        return DB::transaction(function () use ($vendor, $data) {
            $vendor->update($data);
            return $vendor->fresh();
        });
    }

    public function delete(Vendor $vendor): void
    {
        if ($vendor->bills()->exists()) {
            throw new RuntimeException('Cannot delete a vendor with bills. Deactivate instead.');
        }
        $vendor->delete();
    }
}
